<?php

declare(strict_types=1);

namespace App\Agent;

use App\Contracts\AgentRunHandler;
use App\Mcp\Apps\McpAppTurnContext;
use App\Models\AgentRun;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Widget\WidgetPiiMasker;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Queue entry point that owns run lifecycle, collection and final synthesis. */
final readonly class DefaultAgentRunHandler implements AgentRunHandler
{
    public function __construct(
        private AgentLoop $loop,
        private AgentAnswerSynthesizer $synthesizer,
        private AgentEventPublisher $events,
        private AgentResultProjector $projector,
        private McpAppTurnContext $mcpAppContext,
        private AgentTurnContextBuilder $turnContextBuilder,
        private WidgetPiiMasker $masker,
    ) {}

    public function handle(AgentRun $run): void
    {
        $diagnostics = app(\App\Agent\Debug\RunDiagnostics::class);
        $lockSpan = $diagnostics->begin($run, 'run.lock');
        // A redelivered parent must not finalize a snapshot while another worker
        // is still collecting its branches. Child locks alone cannot prevent it.
        $lock = app(AgentRunControl::class)->executionLock($run);
        if (! $lock->get()) {
            $diagnostics->end($run, $lockSpan, metadata: ['lock_acquired' => false]);
            return;
        }
        $diagnostics->end($run, $lockSpan, metadata: ['lock_acquired' => true]);
        try {
            app(\App\Ai\AgentChatModelScope::class)->within(AgentExecutionContext::fromArray($run->toArray()),
                fn () => app(\App\Services\Qa\CampaignScope::class)->withinRun($run, fn () => $this->handleOwned($run)));
        } finally {
            $lock->release();
            // A fast graph can finish while this worker still owns the parent
            // lock. Its queued continuation may already have been acknowledged
            // by a competing worker; re-drive after releasing our own lock.
            $current = $run->fresh();
            $continuation = $current?->status === AgentRun::STATUS_QUEUED
                && (data_get($current->result_json, 'api_tool_resumptions', []) !== [] || data_get($current->result_json, 'research_continuation') === true);
            if ($current?->usesConcurrentSession()) {
                $research = app(AgentSessionResearch::class);
                $dispatchFailed = $continuation && ! $research->enqueue($current);
                if ($dispatchFailed || $current->isTerminal() || $current->sessionStage() === 'waiting') {
                    $research->startNext($current);
                }
            } elseif ($continuation) {
                app(AgentRunDispatcher::class)->enqueue($current);
            }
        }
    }

    private function handleOwned(AgentRun $run): void
    {
        $run->refresh();
        $researchPhase = $run->sessionStage();
        $delivery = \Illuminate\Support\Facades\Context::getHidden('agent.session_stage');
        if ($researchPhase === 'waiting' || ($delivery !== null && $delivery !== $run->sessionDeliveryStage())) {
            return;
        }
        if ($run->isTerminal() || in_array($run->status, [
            AgentRun::STATUS_AWAITING_CONFIRMATION,
            AgentRun::STATUS_AWAITING_MCP_CONFIRMATION,
            AgentRun::STATUS_AWAITING_MCP_INPUT,
            AgentRun::STATUS_WAITING_MCP_TASK,
        ], true) || ($run->status === AgentRun::STATUS_WAITING_API_TOOL
            && ! data_get($run->result_json, 'wave_dispatching', false))) {
            return;
        }

        $context = $this->context($run);
        try {
            $context->assertMatches($run);
            app(\App\Pact\SessionContinuation::class)->authorizeChild($run);
        } catch (\DomainException) {
            $run->forceFill([
                'status' => AgentRun::STATUS_FAILED,
                'error_code' => 'unauthorized',
                'completed_at' => now(),
            ])->save();
            $this->events->publish(
                $run,
                'run.failed',
                'run.failed',
                data: ['error_code' => 'unauthorized'],
                canCancel: false,
            );

            return;
        }
        $context->activate();
        $started = \Illuminate\Support\Facades\DB::transaction(function () use ($run): bool {
            $locked = AgentRun::query()->forTenant($run->tenant_id)->lockForUpdate()->findOrFail($run->id);
            if ($locked->isTerminal()) {
                return false;
            }
            $locked->forceFill([
                'status' => AgentRun::STATUS_RUNNING,
                'started_at' => $locked->started_at ?? now(),
                'error_code' => null,
                ...isset($locked->result_json['research_continuation'])
                    ? ['result_json' => array_diff_key($locked->result_json, ['research_continuation' => true])] : [],
            ])->save();
            if (! $locked->events()->where('type', 'run.started')->exists()) {
                $this->events->publish($locked, 'run.started', $locked->usesConcurrentSession() ? 'run.assessing' : 'run.started');
            }

            return true;
        }, 3);
        if (! $started) {
            return;
        }
        $run->refresh();

        $diagnostics = app(\App\Agent\Debug\RunDiagnostics::class);
        $executionSpan = $diagnostics->begin($run, 'run.execute', ['runtime' => $diagnostics->runtime()]);
        try {
            while (true) {
                $turnContext = $diagnostics->measure($run, 'context.prepare', fn () => $this->turnContext($run));
                $outcome = match ($researchPhase) {
                    'reassess' => app(AgentSessionResearch::class)->reassess($run, $context),
                    'active' => app(AgentSessionResearch::class)->collect($run, $context, $turnContext),
                    'ready' => app(AgentSessionResearch::class)->result($run),
                    default => $this->loop->run($run, $context, $turnContext),
                };
                if ($outcome->decision === 'continue_research') {
                    $diagnostics->end($run, $executionSpan, metadata: ['operation' => 'research_continuation']);
                    return;
                }
                if ($outcome->requiresInteraction()) {
                    $diagnostics->end($run, $executionSpan, metadata: ['operation' => 'interaction_handoff']);
                    return;
                }
                if ($researchPhase === 'active' || $outcome->decision === 'request_research') {
                    $research = app(AgentSessionResearch::class);
                    if ($researchPhase === 'active') {
                        $research->complete($run, $outcome);
                    } else {
                        $research->request($run, $outcome);
                    }
                    $diagnostics->end($run, $executionSpan);
                    return;
                }

                $contextAttempt = $outcome->stopReason === 'context_answer_attempt';
                if (! $contextAttempt) {
                    $this->events->publish($run, 'synthesis.started', 'synthesis.started');
                }
                $run->refresh();
                if (config('reasoning.enabled') || $run->usesSessionEvidence()) {
                    $safe = app(\App\Agent\Evidence\AgentEvidenceFactory::class)->empty();
                    $safe->import(app(\App\Agent\Evidence\AgentEvidenceAccess::class)->current($run, $context, $outcome->evidence->jsonSerialize(),
                        data_get($run->result_json, 'question_understanding.transition') === 'recap'));
                    $outcome = new AgentLoopOutcome($outcome->decision, $safe, $outcome->completedActions, $outcome->stopReason);
                    $turnContext = $this->turnContext($run);
                    $owner = app(\App\Services\Chat\Reasoning\ConversationReasoning::class)->runOwner($run);
                    if ($owner !== null) {
                        $decodedContext = json_decode($turnContext ?? '{}', true) ?: [];
                        $decodedContext['reasoning']['already_communicated'] = app(\App\Services\Chat\Reasoning\ConversationReasoning::class)
                            ->communicatedForEvidence($owner, $safe->jsonSerialize());
                        $turnContext = json_encode($decodedContext, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                    }
                }
                $answer = $diagnostics->measure($run, 'synthesis.execute', fn () => $this->synthesizer->synthesize(
                    trim((string) data_get($run->input_json, 'question', '')),
                    $context,
                    $outcome,
                    $turnContext,
                    is_array(data_get($run->result_json, 'question_understanding')) ? data_get($run->result_json, 'question_understanding') : null,
                    durableRunId: $run->run_id,
                ));
                $understanding = $run->result_json['question_understanding'] ?? [];
                // Older interpretations could call a request for detail a rewrite.
                // An explicit restriction on the sources remains binding.
                $reopenContext = $outcome->stopReason === 'context_only'
                    && ! isset($run->result_json['session_context_gap'])
                    && ($understanding['follow_up_target'] ?? null) === 'previous_reply'
                    && ($understanding['deliverable']['kind'] ?? null) !== 'rewrite'
                    && in_array($answer->grounding['coverage_validation']['next_action'] ?? null, ['read', 'search'], true);
                if (($contextAttempt || $reopenContext) && $answer->needsSessionSources()) {
                    if ($run->usesConcurrentSession()) {
                        app(AgentSessionResearch::class)->request($run, $outcome, $answer);
                        $diagnostics->end($run, $executionSpan);
                        return;
                    }
                    if ($this->continueAfterContextGap($run, $context, $outcome, $answer)) {
                        $diagnostics->end($run, $executionSpan, metadata: ['operation' => 'context_gap_continuation']);
                        return;
                    }

                    continue;
                }
                $serialCollection = $run->usesSessionEvidence() && ! $run->usesConcurrentSession()
                    && in_array($answer->grounding['coverage_validation']['next_action'] ?? null, ['read', 'search'], true);
                if ($researchPhase === 'ready' || $serialCollection) {
                    $research = app(AgentSessionResearch::class);
                    $depth = $research->nextDepth($run, $outcome, $answer);
                    if ($depth !== null) {
                        if ($run->usesConcurrentSession()) {
                            $research->request($run, $outcome, $answer, $depth);
                        } elseif (! $this->continueAfterContextGap($run, $context, $outcome, $answer, $depth)) {
                            continue;
                        }
                        $diagnostics->end($run, $executionSpan, metadata: ['operation' => 'research_continuation']);
                        return;
                    }
                }
                if ($contextAttempt) {
                    $this->events->publish($run, 'synthesis.started', 'synthesis.started');
                }
                $status = $outcome->decision === 'partial' || $answer->completeness === 'partial'
                    || ($researchPhase === 'ready' && $answer->completeness === 'insufficient')
                    ? AgentRun::STATUS_PARTIAL
                    : AgentRun::STATUS_COMPLETED;
                $saved = $this->finalize($run, $status, $outcome, $answer);
                if (! $saved) {
                    $diagnostics->end($run, $executionSpan, $run->fresh()->status === AgentRun::STATUS_CANCELLED ? 'cancelled' : 'completed', ['operation' => 'terminal_already_recorded']);
                    return;
                }
                $diagnostics->end($run, $executionSpan);

                return;
            }
        } catch (AgentRunCancelledException $exception) {
            $diagnostics->failure($run, $executionSpan, $exception, ['error_handled' => true]);
            // AgentRunControl already persisted and published the cancellation.
        } catch (Throwable $exception) {
            $diagnostics->failure($run, $executionSpan, $exception, ['error_handled' => true]);
            $run->refresh();
            if ($run->isTerminal()) {
                return;
            }
            if (app(VerifiedAnswerCheckpoint::class)->recover($run, 'agent_run_failed')) return;
            Log::error('Agent run failed.', [
                'run_id' => $run->run_id,
                'tenant_id' => $run->tenant_id,
                'project_key' => $run->project_key,
                'status_before_failure' => $run->status,
                'exception_class' => $exception::class,
                'exception_message' => $this->masker->maskString(mb_substr($exception->getMessage(), 0, 1000)),
                'exception_trace' => $exception->getTraceAsString(),
            ]);
            \Illuminate\Support\Facades\DB::transaction(function () use ($run, $exception): void {
                $locked = AgentRun::query()->forTenant($run->tenant_id)->whereKey($run->id)->lockForUpdate()->firstOrFail();
                if ($locked->isTerminal()) {
                    return;
                }
                $locked->forceFill([
                    'status' => AgentRun::STATUS_FAILED,
                    'error_code' => $this->errorCode($exception),
                    'completed_at' => now(),
                ])->save();
                $this->events->publish($locked, 'run.failed', 'run.failed',
                    data: ['error_code' => $locked->error_code], canCancel: false);
            });
        }
    }

    private function continueAfterContextGap(AgentRun $run, AgentExecutionContext $context, AgentLoopOutcome $outcome, AgentAnswer $answer, int $depthFloor = 0): bool
    {
        $queued = app(AgentRunDeadline::class)->activeRemaining($context) !== null
            && config('queue.connections.'.config('queue.default').'.driver') !== 'sync';
        \Illuminate\Support\Facades\DB::transaction(function () use ($run, $outcome, $answer, $queued, $depthFloor): void {
            $locked = AgentRun::query()->forTenant($run->tenant_id)->lockForUpdate()->findOrFail($run->id);
            app(AgentRunControl::class)->ensureActive($locked);
            $locked->forceFill([
                ...$queued ? ['status' => AgentRun::STATUS_QUEUED] : [],
                'result_json' => [...(array) $locked->result_json,
                    'evidence' => $outcome->evidence->jsonSerialize(),
                    'session_context_gap' => [...$answer->sessionGap(), 'depth_floor' => $depthFloor,
                        'source_hashes' => app(AgentSessionResearch::class)->sourceHashes($outcome->evidence)],
                    ...$queued ? ['research_continuation' => true] : [],
                ],
            ])->save();
        });
        $run->refresh();

        return $queued;
    }

    private function finalize(AgentRun $run, string $status, AgentLoopOutcome $outcome, AgentAnswer $answer): bool
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($run, $status, $outcome, $answer): bool {
            $locked = AgentRun::query()->forTenant($run->tenant_id)->whereKey($run->id)->lockForUpdate()->firstOrFail();
            if ($locked->isTerminal()) {
                return false;
            }
            $locked->forceFill([
                'status' => $status,
                'result_json' => array_merge((array) $locked->result_json, [
                    'phase' => 'final', 'decision' => $outcome->decision,
                    'stop_reason' => $outcome->stopReason, 'response' => $answer->jsonSerialize(),
                    ...$locked->usesSessionEvidence() ? ['evidence' => $outcome->evidence->jsonSerialize()] : [],
                ]),
                'completed_at' => now(), 'error_code' => null,
            ])->save();
            $this->projector->project($locked, $answer);
            app(\App\Services\Chat\Reasoning\ConversationReasoning::class)->finishRun($locked, $outcome->evidence->jsonSerialize(), $answer);
            app(AgentResearchProgress::class)->finished($locked, $answer);
            $this->events->publish(
                $locked,
                $status === AgentRun::STATUS_PARTIAL ? 'run.partial' : 'run.completed',
                $status === AgentRun::STATUS_PARTIAL ? 'run.partial' : 'run.completed',
                data: ['response' => $answer->jsonSerialize()],
                canCancel: false,
            );

            return true;
        });
    }

    private function turnContext(AgentRun $run): ?string
    {
        $appId = data_get($run->input_json, 'mcp_app_id');
        $user = $run->user;
        $conversation = $run->conversation;
        $mcpAppContext = null;

        if (is_string($appId) && $user instanceof User && $conversation instanceof Conversation) {
            $previousTimezone = date_default_timezone_get();
            try {
                // Connector timestamps are persisted in the application timezone.
                // Agent runs use the actor's timezone for localized output, so use
                // the storage timezone while authorizing expiry-bound app context.
                date_default_timezone_set((string) config('app.timezone', 'UTC'));

                $mcpAppContext = $this->mcpAppContext->resolve($appId, $user, $conversation);
            } finally {
                date_default_timezone_set($previousTimezone);
            }
        }

        $context = $this->turnContextBuilder->build($run, $mcpAppContext);

        return $context === []
            ? null
            : json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function context(AgentRun $run): AgentExecutionContext
    {
        return AgentExecutionContext::fromArray([
            'run_id' => $run->run_id,
            'tenant_id' => $run->tenant_id,
            'project_key' => $run->project_key,
            'channel' => $run->channel,
            'actor_type' => $run->actor_type,
            'actor_id' => $run->actor_id,
            'locale' => $run->locale,
            'timezone' => $run->timezone,
        ]);
    }

    private function errorCode(Throwable $exception): string
    {
        $message = strtolower($exception->getMessage());
        if (str_contains($message, 'budget') || str_contains($message, 'limit')) {
            return 'budget_exhausted';
        }
        if (str_contains($message, 'unauthor') || str_contains($message, 'scope')) {
            return 'unauthorized';
        }

        return 'agent_run_failed';
    }
}

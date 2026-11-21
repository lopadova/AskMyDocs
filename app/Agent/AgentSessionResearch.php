<?php

declare(strict_types=1);

namespace App\Agent;

use App\Agent\Evidence\AgentEvidenceFactory;
use App\Agent\Evidence\AgentSessionEvidence;
use App\Models\AgentRun;
use App\Models\Conversation;
use Illuminate\Support\Facades\DB;

/** Owns source collection and the FIFO of research requests for a text session. */
final readonly class AgentSessionResearch
{
    public function __construct(
        private AgentLoop $loop,
        private AgentEvidenceFactory $evidence,
        private AgentSessionEvidence $retainedEvidence,
        private AgentRunDispatcher $dispatcher,
        private AgentEventPublisher $events,
        private AgentResultProjector $projector,
        private AgentRunControl $control,
    ) {}

    public function request(AgentRun $run, AgentLoopOutcome $outcome, ?AgentAnswer $partial = null): void
    {
        DB::transaction(function () use ($run, $outcome, $partial): void {
            $locked = AgentRun::query()->forTenant($run->tenant_id)->lockForUpdate()->findOrFail($run->id);
            if ($locked->isTerminal() || isset($locked->result_json['session_research'])) {
                return;
            }
            $showPartial = $partial?->completeness === 'partial'
                && ($partial->grounding['claims'] ?? []) !== [];
            $locked->forceFill([
                'status' => AgentRun::STATUS_QUEUED,
                'result_json' => [...(array) $locked->result_json, 'retrieval_completed' => true,
                    'evidence' => $outcome->evidence->jsonSerialize(),
                    'session_research' => ['state' => 'waiting'],
                    'session_context_gap' => $partial === null ? null : [
                        'completeness' => $partial->completeness, 'limitations' => $partial->limitations,
                        'coverage_sufficient' => data_get($partial->grounding, 'coverage_validation.sufficient'),
                    ],
                ],
            ])->save();
            if ($showPartial) {
                $this->projector->project($locked, $partial, interim: true);
            }
            $this->events->publish($locked, 'research.queued', 'research.queued', data: ['interim_reply' => $showPartial]);
        }, 3);
    }

    public function startNext(AgentRun $run): void
    {
        if (! $run->usesConcurrentSession()) {
            return;
        }
        $next = DB::transaction(function () use ($run): ?AgentRun {
            $conversation = Conversation::query()->forTenant($run->tenant_id)->where('user_id', $run->user_id)
                ->lockForUpdate()->find($run->conversation_id, ['id']);
            if ($conversation === null) {
                return null;
            }
            $pending = AgentRun::query()->forTenant($run->tenant_id)->where('conversation_id', $run->conversation_id)
                ->where('user_id', $run->user_id)
                ->where('input_json->concurrent_session', true)
                ->whereNotIn('status', [AgentRun::STATUS_COMPLETED, AgentRun::STATUS_PARTIAL, AgentRun::STATUS_CANCELLED, AgentRun::STATUS_FAILED]);
            if ((clone $pending)->where('result_json->session_research->state', 'active')->exists()) {
                return null;
            }
            // An older turn still checking the session may need the first research slot.
            $candidate = $pending->where(fn ($query) => $query->whereNull('result_json->session_research->state')
                ->orWhere('result_json->session_research->state', 'waiting'))->orderBy('id')
                ->first(['id', 'result_json->session_research->state as research_state']);
            if ($candidate?->research_state !== 'waiting') {
                return null;
            }
            $locked = AgentRun::query()->forTenant($run->tenant_id)->lockForUpdate()->findOrFail($candidate->id);
            if ($locked->isTerminal()) {
                return null;
            }
            $locked->forceFill(['result_json' => [...$locked->result_json, 'session_research' => ['state' => 'active']]])->save();

            return $locked;
        }, 3);

        if ($next !== null && ! $this->enqueue($next)) {
            $this->startNext($next);
        }
    }

    public function enqueue(AgentRun $run): bool
    {
        try {
            $this->dispatcher->enqueue($run);

            return true;
        } catch (\Throwable $exception) {
            report($exception);
            DB::transaction(function () use ($run): void {
                $locked = AgentRun::query()->forTenant($run->tenant_id)->lockForUpdate()->findOrFail($run->id);
                if ($locked->status === AgentRun::STATUS_QUEUED && $locked->sessionStage() === $run->sessionStage()) {
                    $locked->forceFill(['status' => AgentRun::STATUS_FAILED, 'error_code' => 'session_dispatch_failed', 'completed_at' => now()])->save();
                    $this->events->publish($locked, 'run.failed', 'run.failed', canCancel: false);
                }
            });

            return false;
        }
    }

    public function collect(AgentRun $run, AgentExecutionContext $context, ?string $turnContext): AgentLoopOutcome
    {
        if (! isset($run->result_json['session_research']['collection_started'])) {
            // Earlier queued requests may have obtained new evidence while this turn was waiting.
            $envelope = $this->evidence->empty();
            $retained = $this->retainedEvidence->load($run, $context);
            $original = (array) data_get($run->result_json, 'evidence', []);
            $envelope->import($original);
            $knownExecutions = array_column($envelope->apiTools(), 'execution_id');
            $retained['api_tools'] = array_filter($retained['api_tools'], fn ($tool): bool => ! in_array($tool['execution_id'] ?? null, $knownExecutions, true));
            $envelope->import($retained);
            DB::transaction(function () use ($run, $envelope): void {
                $locked = AgentRun::query()->forTenant($run->tenant_id)->lockForUpdate()->findOrFail($run->id);
                $this->control->ensureActive($locked);
                $locked->forceFill(['result_json' => [...$locked->result_json, 'evidence' => $envelope->jsonSerialize(),
                    'session_research' => ['state' => 'active', 'collection_started' => true]]])->save();
                $this->events->publish($locked, 'research.started', 'research.started');
            }, 3);
            $run->refresh();
        }

        return $this->loop->run($run, $context, $turnContext, collectOnly: true);
    }

    public function complete(AgentRun $run, AgentLoopOutcome $outcome): void
    {
        DB::transaction(function () use ($run, $outcome): void {
            $locked = AgentRun::query()->forTenant($run->tenant_id)->lockForUpdate()->findOrFail($run->id);
            $this->control->ensureActive($locked);
            $locked->forceFill(['status' => AgentRun::STATUS_QUEUED, 'result_json' => [...(array) $locked->result_json,
                'evidence' => $outcome->evidence->jsonSerialize(), 'research_continuation' => true,
                'session_research' => ['state' => 'ready', 'decision' => $outcome->decision, 'stop_reason' => $outcome->stopReason],
            ]])->save();
        });
    }

    public function result(AgentRun $run): AgentLoopOutcome
    {
        $evidence = $this->evidence->empty();
        $evidence->import((array) data_get($run->result_json, 'evidence', []));

        return new AgentLoopOutcome((string) data_get($run->result_json, 'session_research.decision'), $evidence, [],
            data_get($run->result_json, 'session_research.stop_reason'));
    }
}

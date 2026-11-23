<?php

declare(strict_types=1);

namespace App\Agent;

use App\Agent\Budget\AgentBudgetTracker;
use App\Agent\Capabilities\AgentCapabilitySnapshotBuilder;
use App\Agent\Evidence\AgentEvidenceAccess;
use App\Agent\Evidence\AgentEvidenceEnvelope;
use App\Agent\Evidence\AgentEvidenceFactory;
use App\Agent\Evidence\AgentSessionEvidence;
use App\Agent\Grounding\CollectedEvidenceAnswer;
use App\Agent\Tools\AgentToolRegistry;
use App\Agent\Waves\AgentWaveCoordinator;
use App\Models\AgentRun;
use App\Models\Conversation;
use App\Services\Chat\QuestionUnderstanding;
use Illuminate\Support\Facades\DB;

/** Owns source collection and the FIFO of research requests for a text session. */
final readonly class AgentSessionResearch
{
    public function __construct(
        private AgentWaveCoordinator $waves,
        private AgentToolRegistry $tools,
        private AgentCapabilitySnapshotBuilder $capabilities,
        private AgentAnswerSynthesizer $answers,
        private AgentEvidenceFactory $evidence,
        private AgentSessionEvidence $retainedEvidence,
        private AgentRunDispatcher $dispatcher,
        private AgentEventPublisher $events,
        private AgentResultProjector $projector,
        private AgentRunControl $control,
    ) {}

    public function request(AgentRun $run, AgentLoopOutcome $outcome, ?AgentAnswer $answer = null, int $depthFloor = 0): void
    {
        DB::transaction(function () use ($run, $outcome, $answer, $depthFloor): void {
            $locked = AgentRun::query()->forTenant($run->tenant_id)->lockForUpdate()->findOrFail($run->id);
            if ($locked->isTerminal() || $locked->sessionDeliveryStage() !== $run->sessionDeliveryStage()) {
                return;
            }

            $state = $locked->result_json ?? [];
            $stage = $locked->sessionStage();
            $firstRequest = $stage === 'conversation';
            if (! $firstRequest && ! in_array($stage, ['reassess', 'ready'], true)) {
                return;
            }
            $alreadyShown = (bool) data_get($state, 'session_research.interim_sent');
            $showPartial = ! $alreadyShown && $answer?->hasUsefulInterimAnswer();
            $state['retrieval_completed'] = true;
            $state['evidence'] = $outcome->evidence->jsonSerialize();
            $state['session_research'] = ['state' => $firstRequest ? 'waiting' : 'active', 'interim_sent' => $alreadyShown || $showPartial];
            if (! $firstRequest) {
                $state['session_research']['depth_floor'] = $depthFloor;
                $state['session_research']['baseline'] = $this->sourceHashes($outcome->evidence);
                $state['research_continuation'] = true;
                if ($stage === 'ready') {
                    // Other questions can read the completed collection while this
                    // turn researches its remaining gap.
                    $state['session_research']['published_evidence'] = $state['evidence'];
                }
            }
            if ($answer !== null) {
                $state['session_context_gap'] = $answer->sessionGap();
            }
            $locked->forceFill(['status' => AgentRun::STATUS_QUEUED, 'result_json' => $state])->save();
            if ($showPartial) {
                $this->projector->project($locked, $answer, interim: true);
            }
            if ($firstRequest || $showPartial) {
                $this->events->publish($locked, 'research.queued',
                    $showPartial ? 'research.queued_after_partial' : 'research.queued_without_answer',
                    data: ['interim_reply' => $showPartial]);
            }
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
            if ((clone $pending)->whereIn('result_json->session_research->state', ['reassess', 'active', 'ready'])->exists()) {
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
            $state = $locked->result_json;
            $state['session_research']['state'] = 'reassess';
            $locked->forceFill(['result_json' => $state])->save();

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
                if ($locked->status === AgentRun::STATUS_QUEUED && $locked->sessionDeliveryStage() === $run->sessionDeliveryStage()) {
                    $locked->forceFill(['status' => AgentRun::STATUS_FAILED, 'error_code' => 'session_dispatch_failed', 'completed_at' => now()])->save();
                    $this->events->publish($locked, 'run.failed', 'run.failed', canCancel: false);
                }
            });

            return false;
        }
    }

    public function reassess(AgentRun $run, AgentExecutionContext $context): AgentLoopOutcome
    {
        // No collection has started for this turn. Rebuild from the latest
        // authorized session sources, including the preceding FIFO owner's result.
        $evidence = $this->evidence->empty();
        $original = app(AgentEvidenceAccess::class)->current($run, $context, (array) data_get($run->result_json, 'evidence', []));
        $retained = $this->retainedEvidence->load($run, $context);
        $known = array_column($retained['api_tools'], 'execution_id');
        $original['api_tools'] = array_filter($original['api_tools'], fn ($item) => ! in_array($item['execution_id'], $known, true));
        $evidence->import($retained);
        $evidence->import($original);

        return AgentLoopOutcome::assessSession($evidence, QuestionUnderstanding::fromArray($run->result_json['question_understanding'] ?? []));
    }

    public function collect(AgentRun $run, AgentExecutionContext $context, ?string $turnContext): AgentLoopOutcome
    {
        if (! data_get($run->result_json, 'session_research.collection_started')) {
            $legacyReservation = ! isset($run->result_json['session_research']['baseline']);
            DB::transaction(function () use ($run, $legacyReservation): void {
                $locked = AgentRun::query()->forTenant($run->tenant_id)->lockForUpdate()->findOrFail($run->id);
                $this->control->ensureActive($locked);
                $state = $locked->result_json;
                // Pre-upgrade active reservations went straight from FIFO to the research worker.
                if ($legacyReservation) {
                    $state['session_research']['state'] = 'reassess';
                    $state['research_continuation'] = true;
                    $locked->forceFill(['status' => AgentRun::STATUS_QUEUED, 'result_json' => $state])->save();
                    return;
                }
                $state['session_research']['collection_started'] = true;
                $locked->forceFill(['result_json' => $state])->save();
                $this->events->publish($locked, 'research.started', 'research.started');
            }, 3);
            $run->refresh();
            if ($legacyReservation) {
                return new AgentLoopOutcome('continue_research', $this->evidence->empty(), []);
            }
        }

        return $this->execute($run, $context, $turnContext);
    }

    public function execute(AgentRun $run, AgentExecutionContext $context, ?string $turnContext): AgentLoopOutcome
    {
        $tools = $this->tools->forResearch($run, $context);

        return $this->waves->run($run, $context, $turnContext, $tools, $this->capabilities->build($tools));
    }

    public function respond(AgentRun $run, AgentExecutionContext $context, AgentLoopOutcome $sources, ?string $turnContext): AgentSessionResearchResult
    {
        $question = trim((string) data_get($run->input_json, 'question'));
        $understanding = $run->result_json['question_understanding'] ?? null;
        try {
            $answer = $this->answers->synthesize($question, $context, $sources, $turnContext, $understanding,
                durableRunId: $run->run_id, recoverCollectedEvidence: true);
        } catch (AgentDraftUnavailable $exception) {
            $this->control->ensureActive($run);
            $safe = $this->evidence->empty();
            $safe->import(app(AgentEvidenceAccess::class)->current($run, $context, $sources->evidence->jsonSerialize()));
            $sources = new AgentLoopOutcome('partial', $safe, $sources->completedActions, 'synthesis_unavailable');
            $answer = app(CollectedEvidenceAnswer::class)->recover($run, $safe->jsonSerialize(), $context->locale, $exception);
        }

        return new AgentSessionResearchResult($answer, $sources);
    }

    public function nextDepth(AgentRun $run, AgentLoopOutcome $outcome, AgentAnswer $answer): ?int
    {
        $baseline = $run->usesConcurrentSession() ? data_get($run->result_json, 'session_research.baseline', [])
            : data_get($run->result_json, 'session_context_gap.source_hashes', []);
        if (! $answer->needsSessionSources() || ($answer->limitations === [] && ! isset($answer->grounding['coverage_validation']['next_action']))
            || ! in_array($outcome->stopReason, [null, 'lookups_already_observed'], true)
            || array_diff($this->sourceHashes($outcome->evidence), $baseline) === []) {
            return null;
        }
        $depth = (int) $run->toolExecutions()->where('status', 'completed')->max('result_meta_json->dependency_depth');
        if ($depth < 1 || $depth >= (new AgentBudgetTracker($run))->depthLevel()) {
            return null;
        }

        return $depth;
    }

    /** Compare source contents, independent of execution IDs and retrieval timestamps. */
    public function sourceHashes(AgentEvidenceEnvelope $evidence): array
    {
        $hashes = array_column($evidence->apiTools(), 'evidence_hash');
        foreach ($evidence->documents() as $document) {
            array_push($hashes, ...array_column($document['evidence'], 'evidence_hash'));
        }

        return array_values(array_unique($hashes));
    }

    public function complete(AgentRun $run, AgentSessionResearchResult $result): void
    {
        DB::transaction(function () use ($run, $result): void {
            $outcome = $result->sources;
            $locked = AgentRun::query()->forTenant($run->tenant_id)->lockForUpdate()->findOrFail($run->id);
            $this->control->ensureActive($locked);
            $state = $locked->result_json;
            unset($state['session_research']['published_evidence']);
            $state['evidence'] = $outcome->evidence->jsonSerialize();
            $state['research_continuation'] = true;
            $state['session_research'] = [...$state['session_research'], 'state' => 'ready',
                'decision' => $outcome->decision, 'stop_reason' => $outcome->stopReason, 'result' => $result->checkpoint()];
            $locked->forceFill(['status' => AgentRun::STATUS_QUEUED, 'result_json' => $state])->save();
        });
    }

    public function result(AgentRun $run): AgentLoopOutcome
    {
        $evidence = $this->evidence->empty();
        $evidence->import((array) data_get($run->result_json, 'evidence', []));

        return new AgentLoopOutcome((string) data_get($run->result_json, 'session_research.decision'), $evidence, $run->result_json['completed_actions'] ?? [],
            data_get($run->result_json, 'session_research.stop_reason'));
    }
}

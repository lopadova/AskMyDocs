<?php

declare(strict_types=1);

namespace App\Agent;

use App\Agent\Budget\AgentBudgetTracker;
use App\Agent\Evidence\AgentEvidenceEnvelope;
use App\Agent\Evidence\AgentEvidenceFactory;
use App\Agent\Evidence\ResearchEvidence;
use App\Models\AgentRun;
use App\Services\Chat\QuestionUnderstanding;
use App\Services\Chat\Reasoning\ResearchFanout;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/** Independent collection runs; only the parent synthesizes, validates and saves a message. */
class AgentResearchCoordinator
{
    public function collect(AgentRun $parent, AgentEvidenceEnvelope $initial, QuestionUnderstanding $understanding): AgentLoopOutcome
    {
        app(AgentResearchProgress::class)->planned($parent, $understanding);
        $runs = DB::transaction(function () use ($parent, $initial, $understanding) {
            $locked = AgentRun::query()->forTenant($parent->tenant_id)->whereKey($parent->id)->lockForUpdate()->firstOrFail();
            $ids = data_get($locked->result_json, 'research_runs', []);
            $budget = new AgentBudgetTracker($locked);
            foreach ($understanding->subquestions as $index => $sub) {
                if (isset($ids[$index])) {
                    continue;
                }
                $branch = $understanding->forSubquestion($index);
                $input = [...$parent->input_json, 'question' => $branch->intent,
                    'research_parent_id' => $parent->id, 'research_flow_id' => $index];
                unset($input['selection']);
                $initialEvidence = ResearchEvidence::forFlow($initial->jsonSerialize(), $index);
                $run = AgentRun::create([
                    ...$parent->only(['tenant_id', 'project_key', 'user_id', 'conversation_id', 'widget_identity_id', 'widget_session_id',
                        'channel', 'actor_type', 'actor_id', 'locale', 'timezone']),
                    'run_id' => (string) Str::uuid(), 'status' => AgentRun::STATUS_QUEUED, 'input_json' => $input,
                    'budget_json' => ['research_allocation' => $budget->researchAllocation($index, count($understanding->subquestions))],
                    'counters_json' => ['evidence_bytes' => strlen(json_encode($initialEvidence))],
                    'result_json' => ['retrieval_completed' => true, 'question_understanding' => $branch->toArray(),
                        'evidence' => $initialEvidence],
                ]);
                $ids[$index] = $run->id;
            }
            $locked->forceFill(['result_json' => [...$locked->result_json, 'research_runs' => $ids,
                'research_kb_flows' => $locked->result_json['research_kb_flows'] ?? $locked->result_json['research_flows'] ?? []]])->save();
            return $ids;
        });
        $tasks = [];
        foreach ($runs as $index => $id) {
            $tenant = $parent->tenant_id;
            $tasks[$index] = static fn () => app(self::class)->runBranch($id, $tenant);
        }
        try {
            app(ResearchFanout::class)->run($tasks);
        } catch (\Throwable) {
            // A process timeout must not erase another branch's durable successful checkpoint.
        }
        $combined = app(AgentEvidenceFactory::class)->empty();
        $completed = $flows = [];
        $totals = ['logical_calls' => 0, 'physical_calls' => 0, 'iterations' => 0, 'evidence_bytes' => 0];
        foreach ($runs as $index => $id) {
            $run = AgentRun::query()->forTenant($parent->tenant_id)->findOrFail($id);
            if (! $run->isTerminal()) {
                // The owned process has exited or timed out. Do not leave an
                // invisible child "running" and block the widget's next turn.
                $run->forceFill(['status' => AgentRun::STATUS_PARTIAL, 'completed_at' => now(),
                    'error_code' => 'research_incomplete'])->save();
                app(AgentResearchProgress::class)->branch($run, 'partial');
            }
            $result = $run->result_json ?? [];
            $combined->import(ResearchEvidence::tagged($result['evidence'] ?? [], $index));
            foreach ($result['completed_actions'] ?? [] as $action) {
                $completed[] = [...$action, 'research_flow_id' => $index];
            }
            $flows[] = ['id' => $index, 'run_id' => $run->run_id, 'question' => $run->input_json['question'],
                'status' => $run->isTerminal() ? $run->status : 'incomplete',
                'stop_reason' => $result['stop_reason'] ?? $run->error_code,
                'tool_executions' => $run->toolExecutions()->pluck('id')->all()];
            foreach ($totals as $key => $value) {
                $totals[$key] += (int) data_get($run->counters_json, $key, 0);
            }
        }
        DB::transaction(function () use ($parent, $totals, $combined, $completed, $flows) {
            $locked = AgentRun::query()->forTenant($parent->tenant_id)->whereKey($parent->id)->lockForUpdate()->firstOrFail();
            app(AgentRunControl::class)->ensureActive($locked);
            $locked->forceFill(['counters_json' => [...($locked->counters_json ?? []), ...$totals],
                'result_json' => [...$locked->result_json, 'evidence' => $combined->jsonSerialize(),
                    'question_understanding' => [...$locked->result_json['question_understanding'], 'independent_research' => true],
                    'completed_actions' => $completed, 'research_flows' => $flows]])->save();
        });
        $parent->refresh();
        $partial = array_filter($flows, fn ($flow) => $flow['status'] !== AgentRun::STATUS_COMPLETED) !== [];
        return new AgentLoopOutcome($partial ? 'partial' : 'answer', $combined, $completed, $partial ? 'partial_research' : 'independent_research_completed');
    }

    public function runBranch(int $id, string $tenant): void
    {
        $previousTenant = app(\App\Support\TenantContext::class)->current();
        $previousPackageTenant = app(\Padosoft\AskMyDocsConnectorBase\Support\TenantContext::class)->current();
        $previousUser = auth()->user();
        $previousLocale = app()->getLocale();
        $previousTimezone = date_default_timezone_get();
        $lock = Cache::lock('agent-research:'.$tenant.':'.$id, (int) config('reasoning.research_timeout_seconds', 180) + 30);
        if (! $lock->get()) {
            return; // Another delivery owns this branch; never repeat its live tool calls.
        }
        $run = null;
        try {
            app(\App\Support\TenantContext::class)->set($tenant);
            $run = AgentRun::query()->forTenant($tenant)->findOrFail($id);
            if ($run->isTerminal()) {
                return;
            }
            $parent = AgentRun::query()->forTenant($tenant)->findOrFail(data_get($run->input_json, 'research_parent_id'));
            $flow = data_get($run->input_json, 'research_flow_id');
            if (data_get($parent->result_json, 'research_runs.'.$flow) !== $run->id) {
                throw new \DomainException('unregistered_research_branch');
            }
            app(AgentRunControl::class)->ensureActive($parent);
            $context = AgentExecutionContext::fromArray($run->toArray());
            $context->assertMatches($run);
            $context->activate();
            auth()->forgetUser();
            if ($run->user !== null) {
                auth()->setUser($run->user);
            }
            $run->forceFill(['status' => AgentRun::STATUS_RUNNING, 'started_at' => $run->started_at ?? now()])->save();
            app(AgentResearchProgress::class)->branch($run, 'researching');
            $outcome = app(AgentLoop::class)->run($run, $context);
            $run->refresh();
            $run->forceFill(['status' => $outcome->decision === 'answer' ? AgentRun::STATUS_COMPLETED : AgentRun::STATUS_PARTIAL,
                'completed_at' => now(), 'result_json' => [...$run->result_json, 'stop_reason' => $outcome->stopReason,
                    'evidence' => $outcome->evidence->jsonSerialize(), 'completed_actions' => $outcome->completedActions]])->save();
            app(AgentResearchProgress::class)->branch($run, $run->status === AgentRun::STATUS_COMPLETED ? 'collected' : 'partial');
        } catch (\Throwable $exception) {
            if ($run !== null) {
                $run->refresh()->forceFill(['status' => $exception instanceof AgentRunCancelledException ? AgentRun::STATUS_CANCELLED : AgentRun::STATUS_FAILED,
                    'completed_at' => now(), 'error_code' => 'research_branch_failed',
                    'result_json' => [...($run->result_json ?? []), 'research_error' => ['exception_class' => $exception::class]]])->save();
                app(AgentResearchProgress::class)->branch($run, $run->status === AgentRun::STATUS_CANCELLED ? 'cancelled' : 'failed');
            }
        } finally {
            $lock->release();
            auth()->forgetUser();
            if ($previousUser !== null) {
                auth()->setUser($previousUser);
            }
            app(\App\Support\TenantContext::class)->set($previousTenant);
            app(\Padosoft\AskMyDocsConnectorBase\Support\TenantContext::class)->set($previousPackageTenant);
            app()->setLocale($previousLocale);
            date_default_timezone_set($previousTimezone);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Agent\Planning;

use App\Agent\AgentExecutionContext;
use App\Agent\Capabilities\AgentCapabilitySnapshot;
use App\Agent\Evidence\AgentEvidenceEnvelope;
use App\Models\AgentRun;
use Illuminate\Support\Facades\DB;

/** A first-wave proposal shares the interpretation call, never its authorization. */
final readonly class AgentInitialPlanning
{
    public function __construct(private AgentPlanner $planner, private AgentPlannerModeResolver $modes) {}

    public function contract(AgentRun $run, AgentExecutionContext $context, array $tools, AgentCapabilitySnapshot $snapshot): ?array
    {
        if (! $this->enabled($context) || data_get($run->result_json, 'completed_actions', []) !== []
            || data_get($run->result_json, 'waves', []) !== [] || data_get($run->input_json, 'research_parent_id') !== null) {
            return null;
        }
        $depth = max(1, min(5, (int) data_get($run->input_json, 'depth', config('agent.depth.default', 3))));
        return $this->planner->initialPlanningContract($context, $tools, $snapshot, $depth);
    }

    public function consume(AgentRun $run, AgentExecutionContext $context, array $tools, AgentCapabilitySnapshot $snapshot, AgentEvidenceEnvelope $evidence): ?AgentPlan
    {
        if (! $this->enabled($context)) return null;
        $proposal = DB::transaction(function () use ($run, $context, $snapshot): ?array {
            $locked = AgentRun::query()->forTenant($context->tenantId)->whereKey($run->id)->lockForUpdate()->firstOrFail();
            $context->assertMatches($locked);
            $state = (array) $locked->result_json;
            $saved = $state['initial_plan'] ?? null;
            if (! is_array($saved) || ($saved['status'] ?? null) !== 'pending') return null;
            $understanding = \App\Services\Chat\QuestionUnderstanding::fromArray((array) ($state['question_understanding'] ?? []));
            $eligible = ! $locked->isTerminal() && $understanding->available && $understanding->needsResearch()
                && ! $understanding->needsClarification && ($state['completed_actions'] ?? []) === []
                && ($state['waves'] ?? []) === [] && ($state['pending_api_tools'] ?? []) === []
                && ! ($state['wave_dispatching'] ?? false) && ! isset($state['pending_api_tool'])
                && (int) data_get($locked->counters_json, 'iterations', 0) === 0
                && is_string($saved['capability_hash'] ?? null) && hash_equals($snapshot->hash, $saved['capability_hash']);
            $state['initial_plan']['status'] = $eligible ? 'consumed' : 'discarded';
            $locked->forceFill(['result_json' => $state])->save();
            $run->refresh();
            return $eligible && is_array($saved['proposal'] ?? null) ? $saved['proposal'] : null;
        });
        if ($proposal === null) return null;
        try {
            if (array_diff(array_keys($proposal), ['decision', 'actions']) !== []
                || ! is_array($proposal['actions'] ?? null) || ! array_is_list($proposal['actions'])) {
                throw new \UnexpectedValueException('Invalid combined plan shape.');
            }
            foreach ($proposal['actions'] as &$action) {
                if (! is_array($action) || array_diff(array_keys($action), ['id', 'tool', 'arguments_json', 'depends_on', 'purpose']) !== []
                    || ! is_string($action['arguments_json'] ?? null)) {
                    throw new \UnexpectedValueException('Invalid combined plan action.');
                }
                $arguments = json_decode($action['arguments_json'], false, 512, JSON_THROW_ON_ERROR);
                if (! $arguments instanceof \stdClass) throw new \UnexpectedValueException('Tool arguments must be an object.');
                $action['arguments'] = json_decode($action['arguments_json'], true, 512, JSON_THROW_ON_ERROR);
                unset($action['arguments_json']);
            }
            unset($action);
            $proposal = (new AgentPlanActionIdNormalizer)->normalize($proposal, []);
            $plan = app(AgentPlanParser::class)->parse($proposal, $tools, (int) config('agent.planner.max_actions_per_plan', 20));
            // An interpretation is not source evidence; research cannot finish
            // before collecting observations merely because its proposal says so.
            if (! $plan->shouldCallTools()) throw new \UnexpectedValueException('Research requires an initial observation.');
            $guard = app(AgentIdentifierSources::class);
            app(AgentPlanArgumentConstraints::class)->validate($plan, $tools,
                $guard->required($tools) ? $guard->forRun($run, $evidence) : []);
            app(AgentLiveSourceCoverage::class)->validate((string) data_get($run->input_json, 'question'), $plan, $tools, []);
            return $plan;
        } catch (\UnexpectedValueException|\JsonException $exception) {
            DB::transaction(function () use ($run, $exception): void {
                $locked = AgentRun::query()->forTenant($run->tenant_id)->whereKey($run->id)->lockForUpdate()->firstOrFail();
                $state = (array) $locked->result_json;
                $state['initial_plan']['status'] = 'rejected';
                $state['initial_plan']['reason'] = $exception instanceof AgentPlanValidationException ? $exception->validationCode : 'invalid_plan';
                $locked->forceFill(['result_json' => $state])->save();
                $run->refresh();
            });
            // Keep the validated interpretation. Only planning needs a fallback.
            return null;
        }
    }

    private function enabled(AgentExecutionContext $context): bool
    {
        return config('agent.waves.enabled', true) && config('agent.waves.combined_initial_planning', true)
            && $this->modes->forContext($context) === 'classic';
    }
}

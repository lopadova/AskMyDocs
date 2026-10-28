<?php

declare(strict_types=1);

namespace App\Agent\Waves;

use App\Agent\AgentExecutionContext;
use App\Agent\AgentRetrievalFiltersFactory;
use App\Agent\AgentRunControl;
use App\Agent\Budget\AgentBudgetTracker;
use App\Agent\Evidence\AgentEvidenceAccess;
use App\Agent\Evidence\AgentEvidenceFactory;
use App\Agent\Planning\AgentIdentifierSources;
use App\Agent\Planning\AgentPlanArgumentConstraints;
use App\Agent\Tools\AgentLiveSourceSelection;
use App\Agent\Tools\AgentServerToolRunner;
use App\Agent\Tools\AgentToolActionResult;
use App\Agent\Tools\AgentToolDefinition;
use App\Agent\Tools\AgentToolRegistry;
use App\Models\AgentRun;
use App\Support\TenantContext;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext as PackageTenantContext;

final class AgentWaveTask
{
    public function execute(array $payload): AgentToolActionResult
    {
        $tenant = app(TenantContext::class)->current();
        $packageTenant = app(PackageTenantContext::class)->current();
        $locale = App::currentLocale();
        $timezone = date_default_timezone_get();
        $user = Auth::user();
        try {
            $context = AgentExecutionContext::fromArray($payload['context']);
            $context->activate();
            $run = AgentRun::query()->forTenant($context->tenantId)->findOrFail($payload['run_id']);
            $context->assertMatches($run);
            Auth::forgetGuards();
            if ($context->actorType === 'user') {
                if ($run->user === null || ! $run->user->is_active || ($context->projectKey !== null
                    && ! $run->user->canReadAllProjects() && ! in_array($context->projectKey, $run->user->allowedProjects(), true))) {
                    throw new \DomainException('agent_principal_not_authorized');
                }
                Auth::setUser($run->user);
            }
            $taskStarted = hrtime(true);
            return app(\App\Agent\Debug\RunDiagnostics::class)->measure($run, 'tool.execute',
                fn () => app(\App\Ai\AgentChatModelScope::class)->within($context, function () use ($run, $context, $payload, $taskStarted): AgentToolActionResult {
                app(AgentRunControl::class)->ensureActive($run);
                $requested = $payload['tool'];
                if (! $requested instanceof AgentToolDefinition) throw new \InvalidArgumentException('invalid_wave_tool');
                $tools = app(AgentLiveSourceSelection::class)->apply(
                    app(AgentToolRegistry::class)->forContext($context, $run->user), data_get($run->input_json, 'live_sources'));
                $tool = $tools[$requested->name] ?? null;
                if (! $tool instanceof AgentToolDefinition || $tool->kind !== $requested->kind
                    || $tool->executorReference !== $requested->executorReference
                    || ($tool->metadata['source_key'] ?? null) !== ($requested->metadata['source_key'] ?? null)
                    || ! $tool->readOnly || ! $tool->idempotent) {
                    throw new \DomainException('wave_tool_no_longer_authorized');
                }
                $arguments = $payload['arguments'];
                $factory = app(AgentEvidenceFactory::class);
                $evidence = $factory->empty();
                $evidence->import(app(AgentEvidenceAccess::class)->current($run, $context,
                    (array) data_get($run->result_json, 'evidence', []),
                    data_get($run->result_json, 'question_understanding.transition') === 'recap'));
                $identifierGuard = app(AgentIdentifierSources::class);
                app(AgentPlanArgumentConstraints::class)->validateArguments($tool, $arguments,
                    identifierSources: $identifierGuard->required([$tool]) ? $identifierGuard->forRun($run, $evidence) : []);
                if ($tool->kind === 'knowledge') {
                    $query = trim((string) ($arguments['query'] ?? ''));
                    if ($query === '') throw new \InvalidArgumentException('knowledge_query_required');
                    $filters = app(AgentRetrievalFiltersFactory::class)->forRun($run, $context);
                    $project = $context->projectKey ?? (count($filters->projectKeys) === 1 ? $filters->projectKeys[0] : null);
                    if (config('kb.investigation.enabled', true) && ($project === null
                        || ! \App\Models\KbRetrievalProfile::query()->forTenant($context->tenantId)->where('project_key', $project)->exists())) {
                        return new AgentToolActionResult(['error' => 'retrieval_profile_required'], 0, false, 'retrieval_profile_required');
                    }
                    $result = app(AgentWaveKnowledgeSearch::class)->search($query, $run, $context, $filters);
                } else {
                    $worker = clone $run;
                    $budget = (array) $worker->budget_json;
                    data_set($budget, 'research_allocation.physical_hard', max(0, (int) $payload['physical_quota']));
                    unset($budget['confirmed_physical_extension'], $budget['confirmed_logical_extension']);
                    $worker->counters_json = [];
                    $worker->budget_json = $budget;
                    $handoff = isset($payload['wave_id']) ? [
                        'agent_run_id' => $run->id, 'wave_id' => $payload['wave_id'],
                        'action_id' => $payload['action_id'], 'execution_id' => $payload['execution_id'],
                        'purpose' => $payload['purpose'], 'arguments' => $arguments,
                        'tool' => ['name' => $tool->name, 'display_name' => $tool->displayName, 'kind' => $tool->kind,
                            'executor_reference' => $tool->executorReference, 'metadata' => $tool->metadata],
                    ] : null;
                    $result = app(AgentServerToolRunner::class)->execute($tool, $arguments, $context, $worker,
                        new AgentBudgetTracker($worker, persist: false), waveHandoff: $handoff);
                }
                app(AgentRunControl::class)->ensureActive($run);
                return new AgentToolActionResult($result->body, $result->physicalRequests, $result->complete, $result->stopReason,
                    [...$result->stats, 'wave_latency_ms' => (int) ((hrtime(true) - $taskStarted) / 1_000_000)]);
            }), ['tool_kind' => $payload['tool']->kind, 'tool_name' => $payload['tool']->name, 'wait_reason' => 'tool_response']);
        } finally {
            Auth::forgetGuards();
            if ($user !== null) Auth::setUser($user);
            app(TenantContext::class)->set($tenant);
            app(PackageTenantContext::class)->set($packageTenant);
            App::setLocale($locale);
            date_default_timezone_set($timezone);
        }
    }
}

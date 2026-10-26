<?php

declare(strict_types=1);

namespace App\ApiTools;

use App\Agent\AgentExecutionContext;
use App\ApiTools\Models\ApiTool;
use App\ApiTools\Models\ApiToolRun;
use App\ApiTools\Models\ApiToolVerification;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Static validation and simulation finish locally; live cases continue from persisted runs. */
final class ToolVerificationService
{
    public function __construct(private readonly DefinitionValidator $validator, private readonly ContentHasher $hasher, private readonly ToolAccess $access) {}

    /** @return array<int,ApiToolVerification> */
    public function start(ApiTool $tool, AgentExecutionContext $context, string $mode = 'full', bool $auto = false): array
    {
        app(ToolLifecycle::class)->assertScope($tool, $context);
        $this->access->assertActor($context);
        if (! in_array($mode, ['full', 'static', 'simulation', 'live'], true)) { throw ValidationException::withMessages(['mode' => 'Unknown verification mode.']); }

        return DB::transaction(function () use ($tool, $context, $mode, $auto): array {
            $tool = ApiTool::query()->forTenant($context->tenantId)->lockForUpdate()->findOrFail($tool->id);
            if ($tool->manual_disabled) { throw new \DomainException('api_tool_manually_disabled'); }
            $definition = $tool->revision->definition;
            $hash = $this->hasher->tool($definition, $tool->tenant_id, $tool->project_key);
            if ($tool->content_hash !== $hash) {
                $tool->content_hash = $hash;
                $tool->verified_scope = null;
                $tool->verification_generation++;
                app(ToolLifecycle::class)->transition($tool, $tool->publication_intent ? 'deprecated' : 'draft', 'dependencies_changed');
            }
            // A full suite is one generation. Independent mode runs retain it so all three
            // kinds can be inspected separately and later combined for publication.
            if ($mode === 'full') { $tool->verification_generation++; $tool->save(); }
            $scope = $this->scopeHash($tool, $context);
            $testsHash = $this->hasher->hash($definition['tests'] ?? []);
            $brief = $auto && ApiToolVerification::query()->forTenant($tool->tenant_id)->where('api_tool_id', $tool->id)
                ->where('content_hash', $hash)->where('scope_hash', $scope)->where('tests_hash', $testsHash)
                ->where('mode', 'live')->where('status', 'passed')->exists();
            $modes = $mode === 'full' ? ['static', 'simulation', 'live'] : [$mode];
            $records = [];
            foreach ($modes as $kind) {
                $verification = ApiToolVerification::query()->create([
                    'tenant_id' => $tool->tenant_id, 'api_tool_id' => $tool->id, 'api_tool_revision_id' => $tool->current_revision_id,
                    'content_hash' => $hash, 'scope_hash' => $scope, 'tests_hash' => $testsHash,
                    'generation' => $tool->verification_generation, 'mode' => $kind, 'status' => 'running', 'created_at' => now(),
                    'results' => ['context' => $context->jsonSerialize(), 'cases' => [], 'auto' => $auto, 'brief' => $brief],
                ]);
                $records[] = $verification;
                try {
                    if ($kind === 'static') {
                        $this->validator->validate($definition, $tool->tenant_id, $tool->project_key);
                        $this->finish($verification, true);
                    } elseif ($kind === 'simulation') {
                        $cases = $this->cases($definition, 'simulation');
                        if ($cases === []) { throw new \DomainException('api_tool_simulation_cases_required'); }
                        $results = $verification->results;
                        foreach ($cases as $i => $case) {
                            $result = app(ToolRuntime::class)->simulate($tool, $case['inputs'] ?? [], $case['fixtures'] ?? [], $context);
                            $results['cases'][$i] = ['name' => $case['name'] ?? (string) $i, 'passed' => $this->assertions($result['output'] ?? [], $case['assertions'] ?? [])];
                        }
                        $verification->results = $results;
                        $verification->save();
                        $this->finish($verification, ! in_array(false, array_column($results['cases'], 'passed'), true));
                    } else {
                        $cases = $this->cases($definition, 'live');
                        if ($cases === []) { throw new \DomainException('api_tool_live_cases_required'); }
                        $results = $verification->results;
                        $results['definitions'] = $brief ? array_slice($cases, 0, 1) : $cases;
                        $verification->results = $results;
                        $verification->save();
                        $this->launchCase($verification, $tool, $context, 0);
                    }
                } catch (\Throwable $e) {
                    $results = $verification->results;
                    // Persist only a code; connector responses and exception messages may contain secrets.
                    $results['error'] = $e instanceof ValidationException ? 'validation_failed' : 'verification_failed';
                    $verification->results = $results;
                    $verification->save();
                    $this->finish($verification, false);
                }
                if ($verification->fresh()->status === 'failed' && $mode === 'full') { break; }
            }
            $this->activateIfVerified($tool->fresh(), $context, $auto);
            return array_map(fn ($v) => $v->fresh(), $records);
        });
    }

    public function consumeRun(ApiToolRun $run): void
    {
        $continuation = $run->continuation ?? [];
        if (($continuation['type'] ?? null) !== 'verification' || ! $run->terminal()) { return; }
        DB::transaction(function () use ($run, $continuation): void {
            $tool = ApiTool::query()->forTenant($run->tenant_id)->lockForUpdate()->find($run->api_tool_id);
            $verification = ApiToolVerification::query()->forTenant($run->tenant_id)->lockForUpdate()->find($continuation['verification_id'] ?? 0);
            if (! $verification || $verification->mode !== 'live' || $verification->status !== 'running'
                || (int) $verification->api_tool_id !== (int) $run->api_tool_id) { return; }
            $results = $verification->results;
            $context = AgentExecutionContext::fromArray($results['context']);
            if (! $tool || ! $this->current($verification, $tool, $context)) { $this->finish($verification, false, 'stale'); return; }
            $index = (int) ($continuation['case_index'] ?? -1);
            if ($index < 0 || isset($results['cases'][$index]) || ($results['active_run_id'] ?? null) !== $run->id
                || (int) $run->api_tool_revision_id !== (int) $verification->api_tool_revision_id || $run->content_hash !== $verification->content_hash) { return; }
            $case = $results['definitions'][$index] ?? null;
            if (! is_array($case)) { $this->finish($verification, false); return; }
            $passed = in_array($run->status, ['completed','partial'], true) && $run->physical_requests > 0 && $this->assertions($run->outputs ?? [], $case['assertions'] ?? []);
            $results['cases'][$index] = ['name' => $case['name'] ?? (string) $index, 'run_id' => $run->id, 'passed' => $passed];
            $results['active_run_id'] = null;
            $verification->results = $results;
            $verification->save();
            if (! $passed) { $this->finish($verification, false); return; }
            if (isset($results['definitions'][$index + 1])) {
                try { $this->launchCase($verification, $tool, $context, $index + 1); }
                catch (\Throwable) { $this->finish($verification, false); }
            } else {
                $this->finish($verification, true);
                $this->activateIfVerified($tool, $context, (bool) ($results['auto'] ?? false));
            }
        });
    }

    public function isVerified(ApiTool $tool, AgentExecutionContext $context): bool
    {
        try {
            app(ToolLifecycle::class)->assertScope($tool, $context);
            $this->access->assertActor($context);
            $this->validator->validate($tool->revision->definition, $tool->tenant_id, $tool->project_key);
            if ($tool->manual_disabled || $tool->content_hash !== $this->hasher->tool($tool->revision->definition, $tool->tenant_id, $tool->project_key)) { return false; }
            $scope = $this->scopeHash($tool, $context);
            $tests = $this->hasher->hash($tool->revision->definition['tests'] ?? []);
            foreach (['static', 'simulation', 'live'] as $mode) {
                $latest = ApiToolVerification::query()->forTenant($tool->tenant_id)->where('api_tool_id', $tool->id)
                    ->where('api_tool_revision_id', $tool->current_revision_id)->where('content_hash', $tool->content_hash)
                    ->where('scope_hash', $scope)->where('tests_hash', $tests)->where('generation', $tool->verification_generation)
                    ->where('mode', $mode)->latest('id')->first();
                if (! $latest || $latest->status !== 'passed') { return false; }
            }
            return true;
        } catch (\DomainException|ValidationException) { return false; }
    }

    public function scopeHash(ApiTool $tool, AgentExecutionContext $context): string
    {
        $ids = [];
        $available = $this->access->routes($context->tenantId, $context->projectKey ?? '')->keyBy('id');
        foreach ($tool->revision->definition['steps'] ?? [] as $step) {
            if (($step['operation'] ?? 'api') === 'api') { $ids[(string) $step['route_id']] = $available->has($step['route_id']); }
        }
        return $this->hasher->hash(['tenant' => $context->tenantId, 'project' => $context->projectKey ?? '', 'actor_type' => $context->actorType, 'actor_id' => $context->actorId, 'dependencies' => $ids]);
    }

    private function current(ApiToolVerification $verification, ApiTool $tool, AgentExecutionContext $context): bool
    {
        try {
            app(ToolLifecycle::class)->assertScope($tool, $context);
            $this->access->assertActor($context);
            $this->validator->validate($tool->revision->definition, $tool->tenant_id, $tool->project_key);
            return ! $tool->manual_disabled && (int) $tool->verification_generation === (int) $verification->generation
                && (int) $tool->current_revision_id === (int) $verification->api_tool_revision_id
                && $tool->content_hash === $verification->content_hash
                && $tool->content_hash === $this->hasher->tool($tool->revision->definition, $tool->tenant_id, $tool->project_key)
                && $verification->scope_hash === $this->scopeHash($tool, $context)
                && $verification->tests_hash === $this->hasher->hash($tool->revision->definition['tests'] ?? []);
        } catch (\DomainException|ValidationException) { return false; }
    }

    private function launchCase(ApiToolVerification $verification, ApiTool $tool, AgentExecutionContext $context, int $index): void
    {
        if (! $this->current($verification, $tool, $context)) { $this->finish($verification, false, 'stale'); return; }
        $results = $verification->results;
        $case = $results['definitions'][$index];
        $run = app(ToolRuntime::class)->start($tool, $case['inputs'] ?? [], $context, 'verification', [
            'type' => 'verification', 'verification_id' => $verification->id, 'case_index' => $index,
        ]);
        $results['active_run_id'] = $run->id;
        $verification->results = $results;
        $verification->save();
    }

    private function activateIfVerified(ApiTool $tool, AgentExecutionContext $context, bool $auto): void
    {
        if (! $this->isVerified($tool, $context)) { return; }
        $tool->verified_scope = $this->scopeHash($tool, $context);
        if ($auto && $tool->publication_intent && ! $tool->manual_disabled) {
            $tool->consecutive_failures = 0;
            app(ToolLifecycle::class)->transition($tool, 'active', 'verification_passed');
        } else { $tool->save(); }
    }

    private function finish(ApiToolVerification $verification, bool $passed, ?string $status = null): void
    {
        $verification->status = $status ?? ($passed ? 'passed' : 'failed');
        $verification->completed_at = now();
        $verification->save();
    }

    private function cases(array $definition, string $mode): array
    {
        return array_values(array_filter($definition['tests'] ?? [], fn ($case) => ($case['mode'] ?? 'simulation') === $mode));
    }

    /** Assertions never coerce types and distinguish a missing path from null. */
    private function assertions(mixed $output, array $assertions): bool
    {
        if ($assertions === []) { return false; }
        foreach ($assertions as $assertion) {
            $missing = new \stdClass;
            $value = ($assertion['path'] ?? '') === '' ? $output : data_get($output, $assertion['path'], $missing);
            $exists = $value !== $missing;
            $expected = $assertion['value'] ?? null;
            $count = is_array($value) || $value instanceof \Countable ? count($value) : null;
            $passed = match ($assertion['operator'] ?? '') {
                'exists' => $exists === ($expected === null ? true : (bool) $expected),
                'equals' => $exists && $value === $expected,
                'type' => $exists && match ($expected) {
                    'array' => is_array($value) && array_is_list($value), 'object' => is_array($value) && (! array_is_list($value) || $value === []),
                    'string' => is_string($value), 'integer' => is_int($value), 'number' => is_int($value) || is_float($value), 'boolean' => is_bool($value), 'null' => $value === null, default => false,
                },
                'count' => $count !== null && $count === $expected,
                'min_count' => $count !== null && is_int($expected) && $count >= $expected,
                'max_count' => $count !== null && is_int($expected) && $count <= $expected,
                default => false,
            };
            if (! $passed) { return false; }
        }
        return true;
    }
}

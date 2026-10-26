<?php

declare(strict_types=1);

namespace App\ApiTools;

use App\Agent\AgentExecutionContext;
use App\ApiTools\Jobs\VerifyApiTool;
use App\ApiTools\Models\ApiTool;
use App\ApiTools\Models\ApiToolRevision;
use App\ApiTools\Models\ApiToolRun;
use App\Models\NotificationEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ToolLifecycle
{
    public function __construct(private readonly DefinitionValidator $validator, private readonly ContentHasher $hasher, private readonly ToolAccess $access) {}

    public function saveDraft(?ApiTool $tool, array $attributes, AgentExecutionContext $context): ApiTool
    {
        $actor = $this->access->assertActor($context);
        if ($tool) { $this->assertScope($tool, $context); }
        $project = $tool ? $tool->project_key : ($context->projectKey ?? '');
        $definition = $this->validator->validate($attributes['definition'] ?? [], $context->tenantId, $project);
        $name = trim((string) ($attributes['name'] ?? $tool?->name ?? ''));
        if ($name === '' || mb_strlen($name) > 255) { throw ValidationException::withMessages(['name' => 'Enter a tool name of at most 255 characters.']); }

        return DB::transaction(function () use ($tool, $attributes, $context, $actor, $definition, $name): ApiTool {
            $tool = $tool ? ApiTool::query()->forTenant($context->tenantId)->lockForUpdate()->findOrFail($tool->id) : new ApiTool([
                'tenant_id' => $context->tenantId, 'project_key' => $context->projectKey ?? '', 'created_by' => $actor->id,
                'failure_threshold' => max(1, (int) config('api-tools.failure_threshold', 3)),
            ]);
            $tool->name = $name;
            $tool->description = $attributes['description'] ?? $tool->description;
            if (array_key_exists('failure_threshold', $attributes)) {
                $validated = \Illuminate\Support\Facades\Validator::make(['failure_threshold' => $attributes['failure_threshold']], ['failure_threshold' => 'required|integer|min:1|max:100'])->validate();
                $tool->failure_threshold = (int) $validated['failure_threshold'];
            }
            $tool->save();
            $revision = ApiToolRevision::query()->create([
                'api_tool_id' => $tool->id, 'tenant_id' => $context->tenantId,
                'number' => 1 + (int) ApiToolRevision::query()->forTenant($context->tenantId)->where('api_tool_id', $tool->id)->max('number'),
                'definition' => $definition, 'definition_hash' => $this->hasher->hash($this->hasher->definition($definition)), 'created_at' => now(),
            ]);
            $tool->current_revision_id = $revision->id;
            $tool->content_hash = $this->hasher->tool($definition, $context->tenantId, $tool->project_key);
            $tool->verification_generation++;
            $tool->verified_scope = null;
            $tool->publication_intent = false;
            $tool->consecutive_failures = 0;
            $this->transition($tool, $tool->manual_disabled ? 'disabled_manual' : 'draft', 'draft_saved');
            return $tool->fresh(['revision']);
        });
    }

    public function publish(ApiTool $tool, AgentExecutionContext $context): ApiTool
    {
        $this->authorize($tool, $context);
        return DB::transaction(function () use ($tool, $context): ApiTool {
            $tool = ApiTool::query()->forTenant($context->tenantId)->lockForUpdate()->findOrFail($tool->id);
            if ($tool->manual_disabled || ! app(ToolVerificationService::class)->isVerified($tool, $context)) {
                throw ValidationException::withMessages(['verification' => 'Current static, simulation and live checks must pass before publication.']);
            }
            $tool->publication_intent = true;
            $tool->consecutive_failures = 0;
            $this->transition($tool, 'active', 'published');
            return $tool->fresh();
        });
    }

    public function disable(ApiTool $tool, AgentExecutionContext $context): ApiTool
    {
        $this->authorize($tool, $context);
        return DB::transaction(function () use ($tool, $context): ApiTool {
            $tool = ApiTool::query()->forTenant($context->tenantId)->lockForUpdate()->findOrFail($tool->id);
            $tool->manual_disabled = true;
            $tool->verification_generation++;
            $this->transition($tool, 'disabled_manual', 'manual_disable');
            return $tool->fresh();
        });
    }

    public function enable(ApiTool $tool, AgentExecutionContext $context): ApiTool
    {
        $this->authorize($tool, $context);
        $tool = DB::transaction(function () use ($tool, $context): ApiTool {
            $tool = ApiTool::query()->forTenant($context->tenantId)->lockForUpdate()->findOrFail($tool->id);
            $tool->manual_disabled = false;
            $tool->verification_generation++;
            $tool->consecutive_failures = 0;
            $this->transition($tool, $tool->publication_intent ? 'deprecated' : 'draft', 'manual_enable');
            return $tool->fresh();
        });
        if ($tool->publication_intent) { VerifyApiTool::dispatch($tool->id, $this->diagnosticContext($tool)->jsonSerialize(), 'full', true); }
        return $tool;
    }

    /** False means the current dependencies or actor scope are no longer usable. */
    public function checkDependencies(ApiTool $tool, AgentExecutionContext $context): bool
    {
        $this->assertScope($tool, $context);
        $available = true;
        try {
            $this->access->assertActor($context);
        } catch (\DomainException $e) {
            // One caller losing access must not disable a shared tool for everyone.
            // The owner-scoped sweep separately handles revocation of its managing actor.
            if ((string) $context->actorId !== (string) $tool->created_by) { return false; }
            $available = false;
        }
        try { $this->validator->validate($tool->revision->definition, $tool->tenant_id, $tool->project_key); }
        catch (ValidationException) { $available = false; }
        $hash = $this->hasher->tool($tool->revision->definition, $tool->tenant_id, $tool->project_key);
        $changed = $tool->content_hash !== $hash;
        if ($changed || (! $available && $tool->status === 'active')) {
            DB::transaction(function () use ($tool, $hash, $available): void {
                $locked = ApiTool::query()->forTenant($tool->tenant_id)->lockForUpdate()->findOrFail($tool->id);
                if ((int) $locked->current_revision_id !== (int) $tool->current_revision_id) { return; }
                $locked->content_hash = $hash;
                $locked->verification_generation++;
                $locked->verified_scope = null;
                $this->transition($locked, $locked->manual_disabled ? 'disabled_manual' : ($locked->publication_intent ? 'deprecated' : 'draft'), $available ? 'dependencies_changed' : 'scope_revoked');
            });
            $tool->refresh();
        }
        if ($available && $tool->publication_intent && ! $tool->manual_disabled && in_array($tool->status, ['deprecated', 'disabled_errors'], true)) {
            $pending = $tool->getConnection()->table('api_tool_verifications')->where('tenant_id', $tool->tenant_id)->where('api_tool_id', $tool->id)
                ->where('generation', $tool->verification_generation)->whereIn('status', ['pending', 'running'])->exists();
            if (! $pending) { VerifyApiTool::dispatch($tool->id, $this->diagnosticContext($tool)->jsonSerialize(), 'full', true); }
        }
        return $available && ! $changed;
    }

    public function recordOutcome(ApiToolRun $run): void
    {
        if ($run->mode !== 'production' || ! $run->terminal()) { return; }
        if ($run->status === 'cancelled' || preg_match('/invalid_input|cancel|budget|limit|scope|authoriz|dependency_unavailable/', (string) $run->error_code)) { return; }
        DB::transaction(function () use ($run): void {
            $run = ApiToolRun::query()->forTenant($run->tenant_id)->lockForUpdate()->findOrFail($run->id);
            $continuation = $run->continuation ?? [];
            if ($continuation['_lifecycle_recorded'] ?? false) { return; }
            $continuation['_lifecycle_recorded'] = true;
            $run->continuation = $continuation;
            $run->save();
            $tool = ApiTool::query()->forTenant($run->tenant_id)->lockForUpdate()->find($run->api_tool_id);
            if (! $tool || (int) $tool->current_revision_id !== (int) $run->api_tool_revision_id || $tool->content_hash !== $run->content_hash || ! $tool->available()) { return; }
            if (in_array($run->status, ['completed', 'partial'], true)) { $tool->consecutive_failures = 0; $tool->save(); return; }
            $tool->consecutive_failures++;
            if ($tool->consecutive_failures >= max(1, $tool->failure_threshold)) {
                $tool->verification_generation++;
                $tool->verified_scope = null;
                $this->transition($tool, 'disabled_errors', 'failure_threshold');
                VerifyApiTool::dispatch($tool->id, $this->diagnosticContext($tool)->jsonSerialize(), 'full', true);
            } else { $tool->save(); }
        });
    }

    public function assertScope(ApiTool $tool, AgentExecutionContext $context): void
    {
        if ($tool->tenant_id !== $context->tenantId || ! in_array($tool->project_key, ['', $context->projectKey ?? ''], true)) { throw new \DomainException('api_tool_scope_mismatch'); }
    }

    private function diagnosticContext(ApiTool $tool): AgentExecutionContext
    {
        return new AgentExecutionContext((string) Str::uuid(), $tool->tenant_id, $tool->project_key ?: null, 'chat', 'user', $tool->created_by ? (string) $tool->created_by : null, 'en', 'UTC');
    }

    private function authorize(ApiTool $tool, AgentExecutionContext $context): void
    {
        $this->assertScope($tool, $context);
        $this->access->assertActor($context);
    }

    /** Caller holds the tool lock. Notification and state change commit together. */
    public function transition(ApiTool $tool, string $status, string $reason): void
    {
        $from = (string) ($tool->status ?: 'draft');
        $tool->status = $status;
        $tool->save();
        if ($from === $status) { return; }
        $key = $this->hasher->hash([$tool->id, $tool->verification_generation, $from, $status, $reason]);
        $inserted = DB::table('api_tool_state_events')->insertOrIgnore([
            'tenant_id' => $tool->tenant_id, 'api_tool_id' => $tool->id, 'from_status' => $from, 'to_status' => $status,
            'reason' => $reason, 'dedup_key' => $key, 'created_at' => now(),
        ]);
        if ($inserted && $tool->created_by && \App\Models\User::query()->whereKey($tool->created_by)->exists()) {
            NotificationEvent::query()->create([
                'tenant_id' => $tool->tenant_id, 'user_id' => $tool->created_by, 'event_type' => 'api_tool_state_changed',
                'payload' => ['tool_id' => $tool->id, 'name' => $tool->name, 'from_status' => $from, 'status' => $status, 'reason' => $reason, 'dedup_key' => $key],
                'channel_dispatch_log' => ['in_app' => ['status' => 'delivered']],
            ]);
        }
    }
}

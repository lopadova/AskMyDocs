<?php

declare(strict_types=1);

namespace App\ApiTools\Jobs;

use App\Agent\AgentExecutionContext;
use App\ApiTools\Models\ApiTool;
use App\ApiTools\Models\ApiToolVerification;
use App\ApiTools\ToolVerificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

final class VerifyApiTool implements ShouldQueueAfterCommit, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;
    public int $uniqueFor = 600;
    public readonly ?int $generation;
    public readonly ?string $contentHash;
    public readonly ?int $revisionId;
    private readonly string $snapshotIdentity;

    /** The release path must use the exact key acquired at dispatch, even after edits. */
    public function uniqueId(): string
    {
        // Already queued payloads from before the snapshot key retain their legacy lock.
        return $this->snapshotIdentity ?? (($this->context['tenant_id'] ?? '').':'.$this->toolId.':'.$this->mode);
    }

    public function __construct(public readonly int $toolId, public readonly array $context, public readonly string $mode = 'full', public readonly bool $auto = false)
    {
        $tool = ApiTool::query()->forTenant((string) ($context['tenant_id'] ?? ''))
            ->where('project_key', $context['project_key'] ?? '')->find($toolId);
        $this->generation = $tool ? (int) $tool->verification_generation : null;
        $this->contentHash = $tool?->content_hash;
        $this->revisionId = $tool?->current_revision_id !== null ? (int) $tool->current_revision_id : null;
        $this->snapshotIdentity = ($context['tenant_id'] ?? '').':'.$toolId.':'.$mode.':'.hash('sha256', json_encode([
            $this->generation, $this->contentHash, $this->revisionId, $context['project_key'] ?? '',
            $context['actor_type'] ?? '', $context['actor_id'] ?? null, $auto,
        ], JSON_THROW_ON_ERROR));
        $this->onQueue(config('api-tools.queue', 'api-tools'));
        if (config('api-tools.connection')) { $this->onConnection(config('api-tools.connection')); }
    }

    public function handle(ToolVerificationService $verification): void
    {
        if (! config('api-tools.enabled', false)) { return; }
        $host = app(\App\Support\TenantContext::class);
        $connector = app(\Padosoft\AskMyDocsConnectorBase\Support\TenantContext::class);
        $before = [$host->current(), $connector->current(), app()->getLocale(), date_default_timezone_get()];
        try {
            $context = AgentExecutionContext::fromArray($this->context);
            $context->activate();
            DB::transaction(function () use ($verification, $context): void {
                $tool = ApiTool::query()->forTenant($context->tenantId)->where('project_key', $context->projectKey ?? '')
                    ->lockForUpdate()->find($this->toolId);
                if (! $tool || $tool->manual_disabled || ($this->auto && ! $tool->publication_intent)) { return; }
                if (! isset($this->snapshotIdentity) || $this->generation !== (int) $tool->verification_generation
                    || $this->contentHash !== $tool->content_hash || $this->revisionId !== (int) $tool->current_revision_id) {
                    $pending = ApiToolVerification::query()->forTenant($context->tenantId)->where('api_tool_id', $tool->id)
                        ->where('api_tool_revision_id', $tool->current_revision_id)->where('generation', $tool->verification_generation)
                        ->where('content_hash', $tool->content_hash)->where('scope_hash', $verification->scopeHash($tool, $context))
                        ->whereIn('status', ['pending', 'running'])->exists();
                    if (! $pending && ! $verification->isVerified($tool, $context)) {
                        // If the most recent enqueue was lost, the obsolete job repairs it;
                        // its own immutable identity can never consume the latest lock.
                        self::dispatch($tool->id, $this->context, $this->mode, $this->auto);
                    }
                    return;
                }
                $verification->start($tool, $context, $this->mode, $this->auto);
            });
        } catch (\DomainException) { /* Revoked scope and manual disable prevail over queued work. */ }
        finally { $host->set($before[0]); $connector->set($before[1]); app()->setLocale($before[2]); date_default_timezone_set($before[3]); }
    }
}

<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ProjectMembership;
use App\Models\User;
use App\Services\Kb\Retrieval\Expansion\Learning\ExpansionLearningService;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * W7 — the HTTP `analyze` action, off the request path: it runs the benchmark
 * gate (corpus ingest + embedding/expansion calls), which must never block a
 * web worker. Tenant is captured at dispatch and re-applied here (R30).
 *
 * The HTTP caller is authorized when the job is DISPATCHED; by the time a
 * worker picks it up the account may have been removed from the tenant or
 * demoted. The job therefore carries the initiating user id and re-authorizes
 * that principal for the captured tenant before touching any learning data or
 * spending on the gate (SEC-AI-ACT-001: async effects reload the initiator and
 * its CURRENT permissions). A principal that no longer qualifies is a no-op,
 * logged without data — never a retry, never an analysis under stale rights.
 */
final class AnalyzeExpansionLearningJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** The roles the dispatching route requires (`role:admin|super-admin`). */
    public const AUTHORIZED_ROLES = ['admin', 'super-admin'];

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $projectKey,
        public readonly int $initiatorId,
    ) {}

    public function handle(ExpansionLearningService $expansionLearningService, TenantContext $tenantContext): void
    {
        if (! $this->initiatorStillAuthorized()) {
            Log::warning('AnalyzeExpansionLearningJob: initiator no longer authorized, analysis skipped', [
                'tenant_id' => $this->tenantId,
                'initiator_id' => $this->initiatorId,
            ]);

            return;
        }

        $previous = $tenantContext->current();
        $tenantContext->set($this->tenantId);
        try {
            $expansionLearningService->analyze($this->tenantId, $this->projectKey, 'user:'.$this->initiatorId);
        } finally {
            $tenantContext->set($previous);
        }
    }

    /** Same two conditions as the route: tenant membership + an admin role, read now. */
    private function initiatorStillAuthorized(): bool
    {
        $user = User::query()->find($this->initiatorId);
        if ($user === null || ! $user->hasAnyRole(self::AUTHORIZED_ROLES)) {
            return false;
        }

        return ProjectMembership::query()
            ->forTenant($this->tenantId)
            ->where('user_id', $user->id)
            ->exists();
    }
}

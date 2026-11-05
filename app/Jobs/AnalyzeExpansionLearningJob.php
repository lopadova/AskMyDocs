<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Kb\Retrieval\Expansion\Learning\ExpansionLearningService;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * W7 — the HTTP `analyze` action, off the request path: it runs the benchmark
 * gate (corpus ingest + embedding/expansion calls), which must never block a
 * web worker. Tenant is captured at dispatch and re-applied here (R30).
 */
final class AnalyzeExpansionLearningJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $projectKey,
        public readonly string $actor,
    ) {}

    public function handle(ExpansionLearningService $expansionLearningService, TenantContext $tenantContext): void
    {
        $previous = $tenantContext->current();
        $tenantContext->set($this->tenantId);
        try {
            $expansionLearningService->analyze($this->tenantId, $this->projectKey, $this->actor);
        } finally {
            $tenantContext->set($previous);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Flow\Persistence;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Collection;
use Padosoft\LaravelFlow\Contracts\StepRunRepository;
use Padosoft\LaravelFlow\Models\FlowStepRecord;

/**
 * Adds the host tenant boundary to the vendor step persistence contract.
 *
 * The vendor Eloquent repository persists steps with an upsert, so model
 * creating hooks do not run for flow_steps. Decorating the public contract
 * keeps the package untouched while ensuring every new step row receives the
 * same tenant as the active flow run.
 */
final readonly class TenantAwareStepRunRepository implements StepRunRepository
{
    public function __construct(
        private StepRunRepository $inner,
        private TenantContext $tenants,
    ) {}

    public function createOrUpdate(string $runId, string $stepName, array $attributes): FlowStepRecord
    {
        $attributes['tenant_id'] ??= $this->tenants->current();

        return $this->inner->createOrUpdate($runId, $stepName, $attributes);
    }

    public function forRun(string $runId): Collection
    {
        return $this->inner->forRun($runId);
    }
}

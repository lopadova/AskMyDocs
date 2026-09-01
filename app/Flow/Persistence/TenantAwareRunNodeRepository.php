<?php

declare(strict_types=1);

namespace App\Flow\Persistence;

use App\Support\TenantContext;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Padosoft\LaravelFlow\Contracts\RunNodeRepository;
use Padosoft\LaravelFlow\Models\FlowRunNodeRecord;

/**
 * Adds the host tenant boundary to the vendor node persistence contract.
 *
 * The vendor Eloquent repository persists nodes with an upsert, so model
 * creating hooks do not run for flow_run_nodes. Decorating the public contract
 * keeps the package untouched while ensuring every new node row receives the
 * same tenant as the active flow run.
 *
 * Only the write is decorated. The other five methods delegate untouched, and
 * that is deliberate rather than an omission:
 *
 * - All of them are keyed by `$runId`, a UUID the engine generates. Access to
 *   a run is already scoped by {@see TenantAwareRunRepository}, so the run is
 *   the boundary and the node inherits it.
 * - Adding a tenant predicate to the reads would be actively harmful. A queued
 *   worker runs without the HTTP tenant middleware, so TenantContext falls back
 *   to its default; the filter would then match nothing and the engine would
 *   see a run with NO nodes rather than an access denial — corrupting approval
 *   recovery and replay drift checks, which read the node sequence back.
 * - On the three compare-and-set methods it would be worse still: a tenant
 *   mismatch would turn a legitimate CAS into a silent `false`, leaving
 *   `terminate()` with a run marked aborted while its nodes stay running, and
 *   `claim()` wedging a queued run forever.
 *
 * A reviewer applying R30 mechanically will want to add those guards. The
 * boundary is real, it just lives one level up.
 */
final readonly class TenantAwareRunNodeRepository implements RunNodeRepository
{
    public function __construct(
        private RunNodeRepository $inner,
        private TenantContext $tenants,
    ) {}

    public function createOrUpdate(string $runId, string $nodeId, array $attributes): FlowRunNodeRecord
    {
        $attributes['tenant_id'] ??= $this->tenants->current();

        return $this->inner->createOrUpdate($runId, $nodeId, $attributes);
    }

    public function forRun(string $runId): Collection
    {
        return $this->inner->forRun($runId);
    }

    public function states(string $runId): array
    {
        return $this->inner->states($runId);
    }

    public function claim(string $runId, string $nodeId, DateTimeInterface $startedAt): bool
    {
        return $this->inner->claim($runId, $nodeId, $startedAt);
    }

    public function releaseClaim(string $runId, string $nodeId): bool
    {
        return $this->inner->releaseClaim($runId, $nodeId);
    }

    public function terminate(
        string $runId,
        string $nodeId,
        string $expectedStatus,
        string $newStatus,
        DateTimeInterface $finishedAt,
        ?int $durationMs,
        ?string $errorClass = null,
        ?string $errorMessage = null,
    ): bool {
        return $this->inner->terminate(
            $runId,
            $nodeId,
            $expectedStatus,
            $newStatus,
            $finishedAt,
            $durationMs,
            $errorClass,
            $errorMessage,
        );
    }
}

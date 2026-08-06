<?php

declare(strict_types=1);

namespace App\Flow\Persistence;

use App\Support\TenantContext;
use Padosoft\LaravelFlow\Contracts\ConditionalRunRepository;
use Padosoft\LaravelFlow\Contracts\RunRepository;
use Padosoft\LaravelFlow\Models\FlowRunRecord;
use RuntimeException;

/**
 * Applies the host tenant boundary to laravel-flow run persistence.
 *
 * The package repository looks idempotency keys up globally, while the host
 * schema deliberately makes them unique per tenant. Reads therefore need the
 * same composite boundary or one tenant can reuse another tenant's run.
 */
final readonly class TenantAwareRunRepository implements ConditionalRunRepository, RunRepository
{
    public function __construct(
        private RunRepository $inner,
        private TenantContext $tenants,
        private ?string $connection,
    ) {
        if (! $inner instanceof ConditionalRunRepository) {
            throw new RuntimeException('The tenant-aware Flow run repository requires conditional updates.');
        }
    }

    public function create(array $attributes): FlowRunRecord
    {
        $attributes['tenant_id'] ??= $this->tenants->current();

        return $this->inner->create($attributes);
    }

    public function update(string $runId, array $attributes): FlowRunRecord
    {
        if ($this->find($runId) === null) {
            throw new RuntimeException(sprintf('Flow run [%s] was not found.', $runId));
        }

        return $this->inner->update($runId, $attributes);
    }

    public function updateWhereStatus(string $runId, string $expectedStatus, array $attributes): ?FlowRunRecord
    {
        if ($this->query()
            ->whereKey($runId)
            ->where('status', $expectedStatus)
            ->doesntExist()) {
            return null;
        }

        /** @var ConditionalRunRepository $inner */
        $inner = $this->inner;

        return $inner->updateWhereStatus($runId, $expectedStatus, $attributes);
    }

    public function find(string $runId): ?FlowRunRecord
    {
        return $this->query()->find($runId);
    }

    public function findByIdempotencyKey(string $idempotencyKey): ?FlowRunRecord
    {
        return $this->query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<FlowRunRecord>
     */
    private function query(): \Illuminate\Database\Eloquent\Builder
    {
        return (new FlowRunRecord)
            ->setConnection($this->connection)
            ->newQuery()
            ->where('tenant_id', $this->tenants->current());
    }
}

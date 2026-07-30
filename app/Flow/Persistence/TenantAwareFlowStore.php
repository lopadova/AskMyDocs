<?php

declare(strict_types=1);

namespace App\Flow\Persistence;

use App\Support\TenantContext;
use Padosoft\LaravelFlow\Contracts\AuditRepository;
use Padosoft\LaravelFlow\Contracts\FlowStore;
use Padosoft\LaravelFlow\Contracts\PayloadRedactor;
use Padosoft\LaravelFlow\Contracts\RedactorAwareFlowStore;
use Padosoft\LaravelFlow\Contracts\RunRepository;
use Padosoft\LaravelFlow\Contracts\StepRunRepository;

/**
 * Host decorator that adds tenant-aware step persistence to laravel-flow.
 */
final readonly class TenantAwareFlowStore implements FlowStore, RedactorAwareFlowStore
{
    public function __construct(
        private FlowStore $inner,
        private TenantContext $tenants,
    ) {}

    public function runs(): RunRepository
    {
        return $this->inner->runs();
    }

    public function steps(): StepRunRepository
    {
        return new TenantAwareStepRunRepository($this->inner->steps(), $this->tenants);
    }

    public function audit(): AuditRepository
    {
        return $this->inner->audit();
    }

    public function withPayloadRedactor(PayloadRedactor $redactor): FlowStore
    {
        if (! $this->inner instanceof RedactorAwareFlowStore) {
            return $this;
        }

        return new self($this->inner->withPayloadRedactor($redactor), $this->tenants);
    }

    public function transaction(callable $callback): mixed
    {
        return $this->inner->transaction($callback);
    }
}

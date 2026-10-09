<?php

declare(strict_types=1);

namespace App\Connectors;

use App\Support\TenantContext;
use Closure;

/** Rebind the host context used by ingestion, deletion and audit in queue workers. */
final readonly class ConnectorTenantScopeMiddleware
{
    public function __construct(private TenantContext $tenants) {}

    public function handle(object $job, Closure $next): mixed
    {
        $previous = $this->tenants->current();
        $this->tenants->set($job->tenantId);
        try {
            return $next($job);
        } finally {
            $this->tenants->set($previous);
        }
    }
}

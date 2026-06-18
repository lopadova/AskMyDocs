<?php

declare(strict_types=1);

namespace App\FinOps;

use App\Support\TenantContext;

/**
 * Resolves the active AskMyDocs tenant for the laravel-ai-finops package.
 *
 * Wired via `config('ai-finops.tenancy.resolver')`. The package's
 * {@see \Padosoft\LaravelAiFinOps\Support\TenantResolver} resolves this
 * class-string out of the container (so the constructor injection works) and
 * invokes it — hence `__invoke()`. We return {@see TenantContext::current()},
 * which is always set (defaults to 'default') behind the global ResolveTenant
 * middleware, so every metered call, budget and rollup is attributed to the
 * same tenant the rest of AskMyDocs is scoped to (R30/R31).
 *
 * A class-string (not a closure) keeps `php artisan config:cache` working.
 */
final class HostTenantResolver
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function __invoke(): string
    {
        return $this->tenant->current();
    }
}

<?php

declare(strict_types=1);

namespace App\Flow\Admin;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Padosoft\LaravelFlow\Contracts\DashboardReadScope;

/**
 * Confines every flow-admin read to the active tenant.
 *
 * Without this the cockpit has no row-level boundary at all. Its authorizer
 * gates the destructive actions per row, but the LIST is a plain role check —
 * `canViewRuns()` returns a boolean and nothing filters what comes back — and
 * in v2 the three `canView*` methods are documented as reserved and not
 * invoked by any controller. So the moment FLOW_ADMIN_ENABLED is true, an
 * admin of one tenant browses every tenant's runs, nodes, audit and
 * approvals. Flow payloads are not redacted by default
 * (KB_PII_REDACT_FLOW_PAYLOADS=false), so those rows can carry raw ingested
 * document text: this is a content boundary, not a metadata one.
 *
 * The tenant is read inside {@see self::apply()}, never captured in the
 * constructor. The read model is bound as a singleton and wired once, so a
 * captured tenant would outlive the request that resolved it and be served to
 * the next one under Octane, Swoole or a long-lived worker — the exact leak
 * this class exists to close. Injecting the TenantContext OBJECT is fine and
 * is not a capture: it is the string that must not be frozen.
 *
 * Safe for the engine because the engine never touches this read model: it
 * goes through the repositories. Only the dashboard reads through here.
 */
final readonly class TenantScopedDashboardReads implements DashboardReadScope
{
    public function __construct(private TenantContext $tenants) {}

    public function apply(Builder $query): Builder
    {
        $tenantId = $this->tenants->current();
        $column = $query->getModel()->qualifyColumn('tenant_id');

        if ($tenantId === '') {
            // Cannot happen through TenantContext today, which always returns a
            // non-empty string. Handled anyway, and handled as an always-false
            // arm rather than an early return: returning the builder untouched
            // reads as "no restriction", so an unresolvable subject would widen
            // into an unrestricted read instead of denying one.
            return $query->whereRaw('1 = 0');
        }

        return $query->where($column, $tenantId);
    }
}

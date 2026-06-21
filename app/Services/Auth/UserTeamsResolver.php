<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Http\Middleware\AuthorizeTenantHeader;
use App\Models\User;
use App\Support\TeamHash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Padosoft\AiActCompliance\MultiTenancy\Models\Tenant;

/**
 * UserTeamsResolver — computes the list of teams (= tenants) the given
 * user can operate in, with the projects they can access inside each.
 *
 * Feeds the additive `teams` key of `GET /api/auth/me` (R27) that the
 * SPA team switcher consumes. The policy MUST mirror what
 * {@see AuthorizeTenantHeader} will actually allow at request time,
 * or the switcher would offer teams whose requests then 403:
 *
 *  - a `project_memberships` row in tenant T          → T is a team
 *  - `tenant.cross-access` permission                 → every active
 *    row of the package `tenants` table is a team
 *  - everyone                                         → `default`
 *    (users carry no tenant_id column, so ResolveTenant treats
 *    `default` as every user's own tenant)
 *
 * The membership query is deliberately NOT `forTenant()`-scoped: this
 * is the one read that needs the cross-tenant view, because its whole
 * purpose is enumerating the tenants a user can switch into. Display
 * names come from the package `tenants` table when a matching slug
 * row exists, falling back to a humanised slug.
 */
final class UserTeamsResolver
{
    /**
     * @return list<array{tenant_id: string, hash: string, name: string, projects: list<array{project_key: string, role: string, scope: array<mixed>}>}>
     */
    public function resolve(User $user): array
    {
        $memberships = $user->projectMemberships()
            ->get(['tenant_id', 'project_key', 'role', 'scope_allowlist']);

        /** @var array<string, list<array{project_key: string, role: string, scope: array<mixed>}>> $projectsByTenant */
        $projectsByTenant = [];
        foreach ($memberships as $membership) {
            $projectsByTenant[$membership->tenant_id][] = [
                'project_key' => $membership->project_key,
                'role' => $membership->role,
                'scope' => $membership->scope_allowlist ?? [],
            ];
        }

        $tenantIds = array_keys($projectsByTenant);

        if ($user->can(AuthorizeTenantHeader::CROSS_ACCESS_PERMISSION)) {
            $tenantIds = array_merge($tenantIds, $this->allActiveTenantSlugs());
        }

        // Every authenticated user can operate in `default` (it is the
        // own-tenant pass-through of AuthorizeTenantHeader), so the list
        // is never empty and there is always a team to bootstrap into.
        $tenantIds[] = 'default';
        $tenantIds = array_values(array_unique($tenantIds));

        $labels = $this->labels($tenantIds);

        $teams = array_map(static fn (string $tenantId): array => [
            'tenant_id' => $tenantId,
            // Unique URL-safe routing segment: the SPA serves every team
            // under /app/{hash}/… — see App\Support\TeamHash.
            'hash' => TeamHash::for($tenantId),
            'name' => $labels[$tenantId] ?? Str::headline($tenantId),
            'projects' => $projectsByTenant[$tenantId] ?? [],
        ], $tenantIds);

        // `default` first (the bootstrap team, keeps single-tenant
        // deployments looking exactly like v3), then alphabetical.
        usort($teams, static function (array $a, array $b): int {
            if ($a['tenant_id'] === 'default') {
                return -1;
            }
            if ($b['tenant_id'] === 'default') {
                return 1;
            }

            return strcmp($a['tenant_id'], $b['tenant_id']);
        });

        return $teams;
    }

    /**
     * Slugs of every active row in the package `tenants` table. Guarded
     * by Schema::hasTable so deployments that never migrated the AI Act
     * compliance package degrade to membership-derived teams only.
     *
     * @return list<string>
     */
    private function allActiveTenantSlugs(): array
    {
        if (! Schema::hasTable('tenants')) {
            return [];
        }

        return Tenant::query()->active()->pluck('slug')->all();
    }

    /**
     * Display names keyed by slug for the given tenant ids.
     *
     * @param list<string> $tenantIds
     * @return array<string, string>
     */
    private function labels(array $tenantIds): array
    {
        if ($tenantIds === [] || ! Schema::hasTable('tenants')) {
            return [];
        }

        return Tenant::query()
            ->whereIn('slug', $tenantIds)
            ->pluck('name', 'slug')
            ->all();
    }
}

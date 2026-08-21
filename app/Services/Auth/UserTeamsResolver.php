<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Admin\TenantBrandingService;
use App\Support\SystemTenantRegistry;
use App\Support\TeamHash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Padosoft\AiActCompliance\MultiTenancy\Models\Tenant;

/**
 * UserTeamsResolver — computes the list of teams (= tenants) the given
 * user can operate in, with the projects they can access inside each.
 *
 * Feeds the additive `teams` key of `GET /api/auth/me` (R27) that the
 * SPA team switcher consumes. The policy MUST mirror what the tenant
 * authorization middleware will actually allow at request time,
 * or the switcher would offer teams whose requests then 403:
 *
 *  - a `project_memberships` row in an active tenant T → T is a team
 *  - no membership                                     → no teams
 *
 * Reserved namespaces (`default` and `system-registration`) never appear,
 * even if stale data contains an explicit membership for one of them.
 *
 * The membership query is deliberately NOT `forTenant()`-scoped: this
 * is the one read that needs the cross-tenant view, because its whole
 * purpose is enumerating the tenants a user can switch into. Display
 * names come from the package `tenants` table when a matching slug
 * row exists, falling back to a humanised slug.
 */
final class UserTeamsResolver
{
    public function __construct(private readonly TenantBrandingService $branding) {}

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
        $tenantIds = array_values(array_filter(
            $tenantIds,
            static fn (string $slug): bool => ! SystemTenantRegistry::isReserved($slug),
        ));
        $tenantIds = $this->activeOrUnregisteredTenantSlugs($tenantIds);
        $projectsByTenant = array_intersect_key($projectsByTenant, array_flip($tenantIds));

        $labels = $this->labels($tenantIds);
        $logoUrls = $this->branding->logoUrls($tenantIds);

        $teams = array_map(static fn (string $tenantId): array => [
            'tenant_id' => $tenantId,
            // Unique URL-safe routing segment: the SPA serves every team
            // under /app/{hash}/… — see App\Support\TeamHash.
            'hash' => TeamHash::for($tenantId),
            'name' => $labels[$tenantId] ?? Str::headline($tenantId),
            'logo_url' => $logoUrls[$tenantId] ?? null,
            'projects' => $projectsByTenant[$tenantId] ?? [],
        ], $tenantIds);

        usort($teams, static fn (array $a, array $b): int => strcmp($a['tenant_id'], $b['tenant_id']));

        return $teams;
    }

    /**
     * Memberships may outlive a tenant's active lifecycle. Keep legacy slugs
     * with no registry row, but hide suspended/archived registry tenants so
     * the switcher never offers a destination the authorization middleware
     * will reject.
     *
     * @param list<string> $tenantIds
     * @return list<string>
     */
    private function activeOrUnregisteredTenantSlugs(array $tenantIds): array
    {
        if ($tenantIds === [] || ! Schema::hasTable('tenants')) {
            return $tenantIds;
        }

        $columns = ['slug', 'status'];
        if (Schema::hasColumn('tenants', 'is_system')) {
            $columns[] = 'is_system';
        }
        $registry = Tenant::query()->whereIn('slug', $tenantIds)->get($columns)->keyBy('slug');

        return array_values(array_filter(
            $tenantIds,
            static function (string $slug) use ($registry): bool {
                $tenant = $registry->get($slug);

                return $tenant === null
                    || ($tenant->status === 'active' && ! (bool) $tenant->getAttribute('is_system'));
            },
        ));
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

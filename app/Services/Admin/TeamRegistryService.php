<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Http\Middleware\AuthorizeTenantHeader;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Services\Admin\Exceptions\TeamRegistryUnavailableException;
use App\Services\Auth\UserTeamsResolver;
use App\Support\TeamHash;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Padosoft\AiActCompliance\MultiTenancy\Models\Tenant;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * TeamRegistryService — the single shared core (R44) behind team
 * (= tenant) create + rename, over the vendor `tenants` registry
 * (`Padosoft\AiActCompliance\MultiTenancy\Models\Tenant`: `slug`, `name`,
 * `status`). The HTTP {@see \App\Http\Controllers\Api\Admin\TeamController}
 * and the `team:create` / `team:rename` Artisan commands are thin adapters
 * over this class — all validation, authorization and the write ordering
 * live here so the two surfaces can never drift.
 *
 * A "team" is a `tenant_id`/`slug` string; its editable display NAME lives
 * on the `tenants` row. That table is optional (it ships with the AI-Act
 * compliance package), so create/rename guard with `Schema::hasTable` and
 * degrade to {@see TeamRegistryUnavailableException} (HTTP 503) rather than
 * a 500 when it is absent (R14/R43).
 *
 * Authorization is cross-tenant by nature (you administer OTHER teams from
 * within your active one), so it is NOT the request's `X-Tenant-Id` scope:
 *   - anyone with the admin/super-admin route gate may CREATE a new team;
 *   - RENAME a team T requires a `project_memberships` row in T OR the
 *     `tenant.cross-access` permission — the exact rule
 *     {@see AuthorizeTenantHeader} enforces on the switcher path. A miss is
 *     a 404 (IDOR-safe — the team's existence stays hidden).
 * `default` is the reserved bootstrap sentinel (no `tenants` row): it is
 * never creatable and never renamable.
 *
 * Team visibility for the list is delegated verbatim to
 * {@see UserTeamsResolver} — the same source the topbar switcher reads — so
 * the manage page and the switcher can never show different sets (R18).
 */
final class TeamRegistryService
{
    private const RESERVED_SLUG = 'default';

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly UserTeamsResolver $teamsResolver,
    ) {}

    /**
     * Teams the user may see/administer, enriched for the admin page.
     *
     * @return list<array{slug: string, name: string, hash: string, status: string, is_default: bool, can_manage: bool, project_count: int, member_count: int}>
     */
    public function manageableTeams(User $user): array
    {
        // Visibility + name + hash come straight from the switcher resolver
        // (membership ∪ cross-access ∪ default) so the two never diverge.
        $teams = $this->teamsResolver->resolve($user);
        $slugs = array_map(static fn (array $t): string => $t['tenant_id'], $teams);

        $projectCounts = $this->projectCounts($slugs);
        $memberCounts = $this->memberCounts($slugs);
        $statuses = $this->statuses($slugs);

        return array_map(function (array $team) use ($user, $projectCounts, $memberCounts, $statuses): array {
            $slug = $team['tenant_id'];

            return [
                'slug' => $slug,
                'name' => $team['name'],
                'hash' => $team['hash'],
                'status' => $statuses[$slug] ?? ($slug === self::RESERVED_SLUG ? 'system' : 'active'),
                'is_default' => $slug === self::RESERVED_SLUG,
                'can_manage' => $this->canManage($user, $slug),
                'project_count' => (int) ($projectCounts[$slug] ?? 0),
                'member_count' => (int) ($memberCounts[$slug] ?? 0),
            ];
        }, $teams);
    }

    /**
     * Create a new team: the `tenants` registry row (name) + one initial
     * project + a membership for the acting user (so it appears in their
     * switcher immediately). Mirrors the proven ordering of
     * {@see \App\Console\Commands\CreateCompanyCommand} minus the new admin
     * user. Atomic — a mid-failure rolls the whole team back.
     *
     * @return array{slug: string, name: string, hash: string, status: string, is_default: bool, can_manage: bool, project_count: int, member_count: int}
     */
    public function create(?string $slug, string $name, User $actor): array
    {
        $this->assertRegistryAvailable();

        $name = trim($name);
        if ($name === '') {
            throw ValidationException::withMessages(['name' => ['The team name is required.']]);
        }

        $slug = $this->resolveSlug($slug, $name);
        $this->assertValidSlug($slug);
        $this->assertUnique($slug);

        // Make the new team active so BelongsToTenant auto-fills tenant_id on
        // the writes below (we also pass it explicitly). Restore afterwards so
        // the caller's context is untouched. set() re-validates the slug.
        $previous = $this->tenantContext->current();
        try {
            $this->tenantContext->set($slug);

            DB::transaction(function () use ($slug, $name, $actor): void {
                Tenant::create([
                    'slug' => $slug,
                    'name' => $name,
                    'status' => 'active',
                ]);

                Project::create([
                    'tenant_id' => $slug,
                    'project_key' => $slug,
                    'name' => $name,
                    'description' => "{$name} knowledge base",
                ]);

                // The membership is what surfaces the team in the actor's
                // switcher (UserTeamsResolver groups memberships by tenant_id).
                ProjectMembership::create([
                    'tenant_id' => $slug,
                    'user_id' => $actor->id,
                    'project_key' => $slug,
                    'role' => 'admin',
                ]);
            });
        } finally {
            $this->tenantContext->set($previous);
        }

        return $this->teamPayload($slug, $actor);
    }

    /**
     * Rename a team — update the `tenants.name` for its slug. Authorizes the
     * TARGET team independently of the request's tenant scope (a cross-tenant
     * registry op), 404-ing on a team the actor may not administer.
     *
     * @return array{slug: string, name: string, hash: string, status: string, is_default: bool, can_manage: bool, project_count: int, member_count: int}
     */
    public function rename(string $slug, string $name, User $actor): array
    {
        $this->assertRegistryAvailable();

        $slug = Str::lower(trim($slug));

        // canManage() rejects the reserved `default` and any team the actor
        // is neither a member of nor has cross-access to. 404, not 403, so a
        // guessed slug never confirms a team's existence.
        if (! $this->canManage($actor, $slug)) {
            throw new NotFoundHttpException('Team not found.');
        }

        $name = trim($name);
        if ($name === '') {
            throw ValidationException::withMessages(['name' => ['The team name is required.']]);
        }

        $tenant = Tenant::query()->bySlug($slug)->first();
        if ($tenant === null) {
            throw new NotFoundHttpException('Team not found.');
        }

        $tenant->update(['name' => $name]);

        return $this->teamPayload($slug, $actor);
    }

    /**
     * True when the actor may administer (rename) the given team: never the
     * reserved `default`, otherwise a membership in the team OR the
     * `tenant.cross-access` permission — the same rule AuthorizeTenantHeader
     * enforces for switching into a team.
     */
    private function canManage(User $user, string $slug): bool
    {
        if ($slug === '' || $slug === self::RESERVED_SLUG) {
            return false;
        }

        if ($user->can(AuthorizeTenantHeader::CROSS_ACCESS_PERMISSION)) {
            return true;
        }

        return DB::table('project_memberships')
            ->where('tenant_id', $slug)
            ->where('user_id', $user->id)
            ->exists();
    }

    private function resolveSlug(?string $slug, string $name): string
    {
        $slug = trim((string) $slug);
        if ($slug !== '') {
            return Str::lower($slug);
        }

        return Str::slug($name);
    }

    private function assertValidSlug(string $slug): void
    {
        if ($slug === self::RESERVED_SLUG) {
            throw ValidationException::withMessages([
                'slug' => ["'default' is reserved for the bootstrap team and cannot be used."],
            ]);
        }

        if (! preg_match('/^[a-z0-9_-]{1,50}$/', $slug)) {
            throw ValidationException::withMessages([
                'slug' => ['The team slug must match /^[a-z0-9_-]{1,50}$/. Provide an explicit slug.'],
            ]);
        }
    }

    /**
     * "Create new" semantics: refuse to reuse a slug already claimed by a
     * `tenants` row OR by any tenant-aware domain rows (projects /
     * memberships) — mirrors CreateCompanyCommand so a rename can never
     * masquerade as a create.
     */
    private function assertUnique(string $slug): void
    {
        $taken = Tenant::query()->where('slug', $slug)->exists()
            || DB::table('projects')->where('tenant_id', $slug)->exists()
            || DB::table('project_memberships')->where('tenant_id', $slug)->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'slug' => ["A team with the slug '{$slug}' already exists."],
            ]);
        }
    }

    private function assertRegistryAvailable(): void
    {
        if (! Schema::hasTable('tenants')) {
            throw new TeamRegistryUnavailableException(
                'The team registry is unavailable on this deployment (the tenants table is not migrated).'
            );
        }
    }

    /**
     * @param  list<string>  $slugs
     * @return array<string, int>
     */
    private function projectCounts(array $slugs): array
    {
        if ($slugs === []) {
            return [];
        }

        return DB::table('projects')
            ->select('tenant_id', DB::raw('COUNT(*) as c'))
            ->whereIn('tenant_id', $slugs)
            ->groupBy('tenant_id')
            ->pluck('c', 'tenant_id')
            ->all();
    }

    /**
     * @param  list<string>  $slugs
     * @return array<string, int>
     */
    private function memberCounts(array $slugs): array
    {
        if ($slugs === []) {
            return [];
        }

        return DB::table('project_memberships')
            ->select('tenant_id', DB::raw('COUNT(DISTINCT user_id) as c'))
            ->whereIn('tenant_id', $slugs)
            ->groupBy('tenant_id')
            ->pluck('c', 'tenant_id')
            ->all();
    }

    /**
     * @param  list<string>  $slugs
     * @return array<string, string>
     */
    private function statuses(array $slugs): array
    {
        if ($slugs === [] || ! Schema::hasTable('tenants')) {
            return [];
        }

        return Tenant::query()
            ->whereIn('slug', $slugs)
            ->pluck('status', 'slug')
            ->all();
    }

    /**
     * @return array{slug: string, name: string, hash: string, status: string, is_default: bool, can_manage: bool, project_count: int, member_count: int}
     */
    private function teamPayload(string $slug, User $user): array
    {
        $tenant = Schema::hasTable('tenants') ? Tenant::query()->bySlug($slug)->first() : null;

        return [
            'slug' => $slug,
            'name' => $tenant?->name ?? Str::headline($slug),
            'hash' => TeamHash::for($slug),
            'status' => $tenant?->status ?? ($slug === self::RESERVED_SLUG ? 'system' : 'active'),
            'is_default' => $slug === self::RESERVED_SLUG,
            'can_manage' => $this->canManage($user, $slug),
            'project_count' => (int) DB::table('projects')->where('tenant_id', $slug)->count(),
            'member_count' => (int) DB::table('project_memberships')->where('tenant_id', $slug)->distinct()->count('user_id'),
        ];
    }
}

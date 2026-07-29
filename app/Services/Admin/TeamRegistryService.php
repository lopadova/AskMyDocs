<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Services\Admin\Exceptions\TeamRegistryUnavailableException;
use App\Services\Auth\UserTeamsResolver;
use App\Support\SystemTenantRegistry;
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
 * (= tenant) create + rename and the tenant/project/membership primitive used
 * by full company provisioning, over the vendor `tenants` registry
 * (`Padosoft\AiActCompliance\MultiTenancy\Models\Tenant`: `slug`, `name`,
 * `status`). The HTTP {@see \App\Http\Controllers\Api\Admin\TeamController}
 * and the `team:create` / `team:rename` Artisan commands are thin adapters
 * over this class. TenantProvisioningService also delegates its tenant bundle
 * here, so HTTP and `company:create` cannot drift on slug or write ordering.
 *
 * A "team" is a `tenant_id`/`slug` string; its editable display NAME lives
 * on the `tenants` row. That table is optional (it ships with the AI-Act
 * compliance package), so create/rename guard with `Schema::hasTable` and
 * degrade to {@see TeamRegistryUnavailableException} (HTTP 503) rather than
 * a 500 when it is absent (R14/R43).
 *
 * Authorization follows operational tenant membership:
 *   - anyone with the admin/super-admin route gate may CREATE a new team;
 *   - RENAME a team T requires a `project_memberships` row in T.
 *     A miss is a 404 (IDOR-safe — the team's existence stays hidden).
 * System administrators manage unassociated tenants only through the global
 * `/api/system-admin/tenants` control plane.
 * `default` is a reserved legacy slug (normally without a `tenants` row): it
 * is never creatable or renamable and is visible only through membership.
 *
 * Team visibility for the list is delegated verbatim to
 * {@see UserTeamsResolver} — the same source the topbar switcher reads — so
 * the manage page and the switcher can never show different sets (R18).
 */
final class TeamRegistryService
{
    /**
     * Tenant-aware tables whose presence of a `tenant_id` proves the slug is
     * already an existing tenant (so a create must NOT claim it). Includes the
     * data-bearing tables that ingest can populate with NO registry row, not
     * just projects/memberships — see {@see self::assertUnique()} (R30).
     *
     * @var list<string>
     */
    private const TENANT_DATA_TABLES = [
        'projects',
        'project_memberships',
        'knowledge_documents',
        'knowledge_chunks',
        'chat_logs',
        'conversations',
        'messages',
        'kb_nodes',
        'kb_edges',
        'kb_canonical_audit',
        'kb_tags',
    ];

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
        // (real memberships only) so the two never diverge.
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
                'status' => $statuses[$slug] ?? ($slug === SystemTenantRegistry::LEGACY_DEFAULT ? 'system' : 'active'),
                'is_default' => $slug === SystemTenantRegistry::LEGACY_DEFAULT,
                // Only offer Rename when a `tenants` registry row actually
                // exists (statuses() is populated only for such slugs) — rename()
                // 404s without one, so the list must not present a team as
                // manageable that the write surface would then reject.
                'can_manage' => $this->canManage($user, $slug) && array_key_exists($slug, $statuses),
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
        return $this->createForMember($slug, $name, $actor);
    }

    /**
     * Validate and inspect a prospective tenant without writing anything.
     *
     * @return array{slug: string, available: bool}
     */
    public function newTeamAvailability(
        ?string $slug,
        string $name,
        bool $requireRegistry = true,
    ): array {
        if ($requireRegistry) {
            $this->assertRegistryAvailable();
        }

        $name = trim($name);
        $this->assertValidName($name);

        $slug = $this->resolveSlug($slug, $name);
        $this->assertValidSlug($slug);

        try {
            $this->assertUnique($slug);
        } catch (ValidationException) {
            return ['slug' => $slug, 'available' => false];
        }

        return ['slug' => $slug, 'available' => true];
    }

    /**
     * Create a new tenant, its initial project and the target user's
     * membership. This is the shared atomic primitive used by the regular
     * Team UI, the system-admin provisioning flow and `company:create`.
     *
     * @return array{slug: string, name: string, hash: string, status: string, is_default: bool, can_manage: bool, project_count: int, member_count: int}
     */
    public function createForMember(
        ?string $slug,
        string $name,
        User $member,
        ?string $projectKey = null,
        string $membershipRole = 'admin',
        bool $requireRegistry = true,
    ): array {
        $availability = $this->newTeamAvailability($slug, $name, $requireRegistry);
        $slug = $availability['slug'];
        if (! $availability['available']) {
            $this->rejectDuplicateSlug($slug);
        }

        $name = trim($name);
        $projectKey = trim((string) $projectKey) ?: $slug;
        if (! preg_match('/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/', $projectKey) || mb_strlen($projectKey) > 120) {
            throw ValidationException::withMessages([
                'project_key' => ['The initial project key must use lowercase words separated by hyphens or underscores (maximum 120 characters).'],
            ]);
        }
        if (! in_array($membershipRole, ['member', 'admin', 'owner'], true)) {
            throw ValidationException::withMessages([
                'membership_role' => ['The membership role must be member, admin, or owner.'],
            ]);
        }

        // Make the new team active so BelongsToTenant auto-fills tenant_id on
        // the writes below (we also pass it explicitly). Restore afterwards so
        // the caller's context is untouched. set() re-validates the slug.
        $previous = $this->tenantContext->current();
        try {
            $this->tenantContext->set($slug);

            DB::transaction(function () use ($slug, $name, $member, $projectKey, $membershipRole): void {
                if (Schema::hasTable('tenants')) {
                    Tenant::create([
                        'slug' => $slug,
                        'name' => $name,
                        'status' => 'active',
                    ]);
                }

                Project::create([
                    'tenant_id' => $slug,
                    'project_key' => $projectKey,
                    'name' => $name,
                    'description' => "{$name} knowledge base",
                ]);

                // The membership is what surfaces the team in the member's
                // switcher (UserTeamsResolver groups memberships by tenant_id).
                ProjectMembership::create([
                    'tenant_id' => $slug,
                    'user_id' => $member->id,
                    'project_key' => $projectKey,
                    'role' => $membershipRole,
                ]);
            });
        } finally {
            $this->tenantContext->set($previous);
        }

        return $this->teamPayload($slug, $member);
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
        // is not a member of. 404, not 403, so a
        // guessed slug never confirms a team's existence.
        if (! $this->canManage($actor, $slug)) {
            throw new NotFoundHttpException('Team not found.');
        }

        $name = trim($name);
        $this->assertValidName($name);

        $tenant = Tenant::query()->bySlug($slug)->first();
        if ($tenant === null) {
            throw new NotFoundHttpException('Team not found.');
        }

        $tenant->update(['name' => $name]);

        return $this->teamPayload($slug, $actor);
    }

    /**
     * True when the actor may administer (rename) the given team: never the
     * reserved `default`, otherwise a membership in the team.
     */
    private function canManage(User $user, string $slug): bool
    {
        if ($slug === '' || SystemTenantRegistry::isReserved($slug)) {
            return false;
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

    private function assertValidName(string $name): void
    {
        if ($name === '') {
            throw ValidationException::withMessages(['name' => ['The team name is required.']]);
        }

        // The vendor `tenants.name` column is varchar(200). Without this guard
        // a >200-char name overflows on Postgres (SQLSTATE 22001) as an
        // uncaught QueryException → a bare 500 with no field error; validate
        // in the core so all surfaces answer a clean 422 instead (R14).
        if (mb_strlen($name) > 200) {
            throw ValidationException::withMessages([
                'name' => ['The team name may not be greater than 200 characters.'],
            ]);
        }
    }

    private function assertValidSlug(string $slug): void
    {
        if (SystemTenantRegistry::isReserved($slug)) {
            throw ValidationException::withMessages([
                'slug' => ["'{$slug}' is reserved for platform workflows and cannot be used."],
            ]);
        }

        if (! preg_match('/^[a-z0-9_-]{1,50}$/', $slug)) {
            throw ValidationException::withMessages([
                'slug' => ['The team slug must match /^[a-z0-9_-]{1,50}$/. Provide an explicit slug.'],
            ]);
        }
    }

    /**
     * "Create new" semantics: refuse to reuse a slug already claimed by ANY
     * tenant — the `tenants` registry row OR any tenant-aware DATA table with
     * a matching `tenant_id`. Checking only projects/memberships would be a
     * cross-tenant escalation hole (R30): a tenant can hold ingested data
     * (knowledge_documents/chunks, chat_logs, graph) with NO projects/
     * memberships/tenants row — connector ingest writes documents WITHOUT a
     * registry row — so claiming that slug here would mint the actor a
     * membership in the victim tenant and grant operational access.
     * Each table is Schema::hasTable-guarded so an unmigrated optional table
     * degrades cleanly.
     */
    private function assertUnique(string $slug): void
    {
        if (Schema::hasTable('tenants') && Tenant::query()->where('slug', $slug)->exists()) {
            $this->rejectDuplicateSlug($slug);
        }

        foreach (self::TENANT_DATA_TABLES as $table) {
            if (Schema::hasTable($table) && DB::table($table)->where('tenant_id', $slug)->exists()) {
                $this->rejectDuplicateSlug($slug);
            }
        }
    }

    private function rejectDuplicateSlug(string $slug): void
    {
        throw ValidationException::withMessages([
            'slug' => ["A team with the slug '{$slug}' already exists."],
        ]);
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
            'status' => $tenant?->status ?? ($slug === SystemTenantRegistry::LEGACY_DEFAULT ? 'system' : 'active'),
            'is_default' => $slug === SystemTenantRegistry::LEGACY_DEFAULT,
            'can_manage' => $this->canManage($user, $slug),
            'project_count' => (int) DB::table('projects')->where('tenant_id', $slug)->count(),
            'member_count' => (int) DB::table('project_memberships')->where('tenant_id', $slug)->distinct()->count('user_id'),
        ];
    }
}

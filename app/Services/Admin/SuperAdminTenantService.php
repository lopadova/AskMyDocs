<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Models\ProjectMembership;
use App\Models\User;
use App\Services\Admin\Exceptions\TeamRegistryUnavailableException;
use App\Support\LikeEscaper;
use App\Support\TeamHash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Padosoft\AiActCompliance\MultiTenancy\Models\Tenant;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Read/update model for the system-wide super-admin tenant control plane.
 *
 * These queries are intentionally global: the tenant registry is the object
 * being administered. Every join back into tenant-aware tables carries an
 * explicit `tenant_id = tenants.slug` (or target slug) constraint.
 */
final class SuperAdminTenantService
{
    private const STATUSES = ['active', 'suspended', 'archived'];

    /**
     * @return array{data: list<array<string,mixed>>, meta: array<string,int>}
     */
    public function paginate(string $search = '', string $status = '', int $page = 1, int $perPage = 25): array
    {
        $this->assertRegistryAvailable();
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $status = trim($status);
        if ($status !== '' && ! in_array($status, self::STATUSES, true)) {
            throw ValidationException::withMessages(['status' => ['Invalid tenant status.']]);
        }

        $query = Tenant::query()
            ->select(['tenants.slug', 'tenants.name', 'tenants.status', 'tenants.created_at', 'tenants.updated_at'])
            ->selectSub(
                DB::table('projects')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('projects.tenant_id', 'tenants.slug'),
                'project_count',
            )
            ->selectSub(
                DB::table('project_memberships')
                    ->selectRaw('COUNT(DISTINCT user_id)')
                    ->whereColumn('project_memberships.tenant_id', 'tenants.slug'),
                'member_count',
            );

        $search = Str::lower(trim($search));
        if ($search !== '') {
            $like = LikeEscaper::contains($search);
            $query->where(function ($q) use ($like): void {
                $q->whereRaw('LOWER(tenants.name) LIKE ? '.LikeEscaper::ESCAPE_SQL, [$like])
                    ->orWhereRaw('LOWER(tenants.slug) LIKE ? '.LikeEscaper::ESCAPE_SQL, [$like]);
            });
        }
        if ($status !== '') {
            $query->where('tenants.status', $status);
        }

        $paginator = $query->orderBy('tenants.name')->orderBy('tenants.slug')
            ->paginate($perPage, ['*'], 'page', $page);

        $data = collect($paginator->items())->map(static fn (Tenant $tenant): array => [
            'slug' => $tenant->slug,
            'name' => $tenant->name,
            'hash' => TeamHash::for((string) $tenant->slug),
            'status' => $tenant->status,
            'project_count' => (int) $tenant->getAttribute('project_count'),
            'member_count' => (int) $tenant->getAttribute('member_count'),
            'created_at' => $tenant->created_at,
            'updated_at' => $tenant->updated_at,
        ])->values()->all();

        return [
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    /**
     * @return array{tenant: array<string,mixed>, users: array{data: list<array<string,mixed>>, meta: array<string,int>}}
     */
    public function detail(string $slug, int $page = 1, int $perPage = 25): array
    {
        $this->assertRegistryAvailable();
        $tenant = $this->tenantOrFail($slug);
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));

        $users = User::withTrashed()
            ->with('roles.permissions')
            ->whereExists(function ($query) use ($tenant): void {
                $query->selectRaw('1')
                    ->from('project_memberships')
                    ->whereColumn('project_memberships.user_id', 'users.id')
                    ->where('project_memberships.tenant_id', $tenant->slug);
            })
            ->orderByRaw('deleted_at IS NOT NULL')
            ->orderBy('name')
            ->paginate($perPage, ['users.*'], 'page', $page);

        $userIds = collect($users->items())->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $memberships = $userIds === []
            ? collect()
            : ProjectMembership::query()
                ->forTenant((string) $tenant->slug)
                ->whereIn('user_id', $userIds)
                ->orderBy('project_key')
                ->get(['id', 'user_id', 'project_key', 'role', 'scope_allowlist'])
                ->groupBy('user_id');

        $userData = collect($users->items())->map(function (User $user) use ($memberships): array {
            $permissions = $user->getAllPermissions()->pluck('name')->sort()->values();
            $allProjectsPermission = config('kb.project_isolation.enabled', false)
                ? 'kb.read.all_projects'
                : 'kb.read.any';

            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_active' => (bool) $user->is_active,
                'deleted_at' => $user->deleted_at,
                'roles' => $user->getRoleNames()->values()->all(),
                'permissions' => $permissions->all(),
                'all_projects' => $permissions->contains($allProjectsPermission),
                'memberships' => collect($memberships->get($user->id, collect()))
                    ->map(static fn (ProjectMembership $membership): array => [
                        'id' => $membership->id,
                        'project_key' => $membership->project_key,
                        'role' => $membership->role,
                        'scope' => $membership->scope_allowlist ?? [],
                    ])->values()->all(),
            ];
        })->values()->all();

        return [
            'tenant' => $this->payload($tenant),
            'users' => [
                'data' => $userData,
                'meta' => [
                    'current_page' => $users->currentPage(),
                    'last_page' => $users->lastPage(),
                    'per_page' => $users->perPage(),
                    'total' => $users->total(),
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function update(string $slug, array $input, User $actor): array
    {
        $this->assertRegistryAvailable();
        $tenant = $this->tenantOrFail($slug);
        $changes = [];

        if (array_key_exists('name', $input)) {
            $name = trim((string) $input['name']);
            if ($name === '' || mb_strlen($name) > 200) {
                throw ValidationException::withMessages([
                    'name' => ['The tenant name is required and may not be greater than 200 characters.'],
                ]);
            }
            $changes['name'] = $name;
        }

        if (array_key_exists('status', $input)) {
            $status = trim((string) $input['status']);
            if (! in_array($status, self::STATUSES, true)) {
                throw ValidationException::withMessages([
                    'status' => ['The status must be active, suspended, or archived.'],
                ]);
            }
            $changes['status'] = $status;
            if ($status !== $tenant->status) {
                if ($status === 'active') {
                    $changes['suspended_at'] = null;
                    $changes['archived_at'] = null;
                } elseif ($status === 'suspended') {
                    $changes['suspended_at'] = now();
                    $changes['archived_at'] = null;
                } else {
                    $changes['archived_at'] = now();
                }
            }
        }

        if ($changes === []) {
            throw ValidationException::withMessages([
                'tenant' => ['Provide a name or status to update.'],
            ]);
        }

        $before = $tenant->only(array_keys($changes));
        $tenant->update($changes);

        Log::notice('Tenant registry updated by super admin.', [
            'actor_user_id' => $actor->id,
            'tenant_id' => $tenant->slug,
            'before' => $before,
            'after' => $changes,
        ]);

        return $this->payload($tenant->fresh());
    }

    private function tenantOrFail(string $slug): Tenant
    {
        $tenant = Tenant::query()->where('slug', Str::lower(trim($slug)))->first();
        if ($tenant === null) {
            throw new NotFoundHttpException('Tenant not found.');
        }

        return $tenant;
    }

    /**
     * @return array<string,mixed>
     */
    private function payload(Tenant $tenant): array
    {
        $slug = (string) $tenant->slug;

        return [
            'slug' => $slug,
            'name' => $tenant->name,
            'hash' => TeamHash::for($slug),
            'status' => $tenant->status,
            'project_count' => (int) DB::table('projects')->where('tenant_id', $slug)->count(),
            'member_count' => (int) DB::table('project_memberships')
                ->where('tenant_id', $slug)->distinct()->count('user_id'),
            'created_at' => $tenant->created_at,
            'updated_at' => $tenant->updated_at,
        ];
    }

    private function assertRegistryAvailable(): void
    {
        if (! Schema::hasTable('tenants')) {
            throw new TeamRegistryUnavailableException(
                'The tenant control plane is unavailable because the tenants registry has not been migrated.'
            );
        }
    }
}

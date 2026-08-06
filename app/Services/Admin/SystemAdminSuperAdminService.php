<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Models\User;
use App\Support\LikeEscaper;
use App\Support\PlatformAccess;
use App\Support\TeamHash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Padosoft\AiActCompliance\MultiTenancy\Models\Tenant;

/**
 * Read-only global roster of tenant Super Admin identities.
 *
 * These queries intentionally cross tenant boundaries and are exposed only
 * through the `platform.admin` control plane. Pagination happens before all
 * enrichment, including tenant counts and tenant association detail.
 */
final class SystemAdminSuperAdminService
{
    private const STATUSES = ['active', 'inactive', 'deleted'];

    /**
     * @return array{data: list<array<string,mixed>>, meta: array<string,int>}
     */
    public function paginate(
        string $search = '',
        string $status = '',
        int $page = 1,
        int $perPage = 25,
    ): array {
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $status = trim($status);
        if ($status !== '' && ! in_array($status, self::STATUSES, true)) {
            throw ValidationException::withMessages([
                'status' => ['The status must be active, inactive, or deleted.'],
            ]);
        }

        $query = User::withTrashed()
            ->whereHas(
                'roles',
                fn ($roles) => $roles->where(
                    'name',
                    PlatformAccess::TENANT_SUPER_ADMIN_ROLE,
                ),
            );

        $search = Str::lower(trim($search));
        if ($search !== '') {
            $like = LikeEscaper::contains($search);
            $query->where(function ($users) use ($like): void {
                $users->whereRaw('LOWER(name) LIKE ? '.LikeEscaper::ESCAPE_SQL, [$like])
                    ->orWhereRaw('LOWER(email) LIKE ? '.LikeEscaper::ESCAPE_SQL, [$like]);
            });
        }

        if ($status === 'active') {
            $query->whereNull('deleted_at')->where('is_active', true);
        } elseif ($status === 'inactive') {
            $query->whereNull('deleted_at')->where('is_active', false);
        } elseif ($status === 'deleted') {
            $query->onlyTrashed();
        }

        $paginator = $query
            ->with('roles:id,name,guard_name')
            ->orderByRaw('deleted_at IS NOT NULL')
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($perPage, ['users.*'], 'page', $page);

        $ids = collect($paginator->items())
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $tenantCounts = $ids === []
            ? collect()
            : DB::table('project_memberships')
                ->whereIn('user_id', $ids)
                ->select('user_id', DB::raw('COUNT(DISTINCT tenant_id) AS tenant_count'))
                ->groupBy('user_id')
                ->pluck('tenant_count', 'user_id');

        $data = collect($paginator->items())->map(
            static fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_active' => (bool) $user->is_active,
                'deleted_at' => $user->deleted_at,
                'is_system_admin' => $user->hasRole(PlatformAccess::SYSTEM_ADMIN_ROLE, 'web'),
                'tenant_count' => (int) $tenantCounts->get($user->id, 0),
            ],
        )->values()->all();

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
     * @return array{user: array<string,mixed>, data: list<array<string,mixed>>, meta: array<string,int>}
     */
    public function tenants(User $user, int $page = 1, int $perPage = 25): array
    {
        abort_unless(
            $user->hasRole(PlatformAccess::TENANT_SUPER_ADMIN_ROLE, 'web'),
            404,
        );

        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));

        $paginator = DB::table('project_memberships')
            ->where('user_id', $user->id)
            ->select(
                'tenant_id',
                DB::raw('COUNT(DISTINCT project_key) AS project_count'),
            )
            ->groupBy('tenant_id')
            ->orderBy('tenant_id')
            ->paginate($perPage, ['*'], 'page', $page);

        $slugs = collect($paginator->items())
            ->pluck('tenant_id')
            ->filter(static fn (mixed $slug): bool => is_string($slug))
            ->values()
            ->all();

        $registered = $slugs !== [] && Schema::hasTable('tenants')
            ? Tenant::query()->whereIn('slug', $slugs)->get()->keyBy('slug')
            : collect();

        $data = collect($paginator->items())->map(
            static function (object $association) use ($registered): array {
                $slug = (string) $association->tenant_id;
                /** @var Tenant|null $tenant */
                $tenant = $registered->get($slug);

                return [
                    'slug' => $slug,
                    'hash' => TeamHash::for($slug),
                    'name' => $tenant?->name ?? Str::headline($slug),
                    'status' => $tenant?->status ?? 'unregistered',
                    'project_count' => (int) $association->project_count,
                ];
            },
        )->values()->all();

        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_active' => (bool) $user->is_active,
                'deleted_at' => $user->deleted_at,
                'is_system_admin' => $user->hasRole(PlatformAccess::SYSTEM_ADMIN_ROLE, 'web'),
            ],
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }
}

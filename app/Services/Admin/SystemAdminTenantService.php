<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Models\AdminCommandAudit;
use App\Models\AdminCommandNonce;
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
 * Read/update model for the system-wide administrator tenant control plane.
 *
 * These queries are intentionally global: the tenant registry is the object
 * being administered. Every join back into tenant-aware tables carries an
 * explicit `tenant_id = tenants.slug` (or target slug) constraint.
 */
final class SystemAdminTenantService
{
    private const STATUSES = ['active', 'suspended', 'archived'];

    private const LIFECYCLE_COMMAND = 'system-admin:tenant-lifecycle';

    private const CONFIRM_TTL_MINUTES = 5;

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
        if (Schema::hasColumn('tenants', 'is_system')) {
            $query->where('tenants.is_system', false);
        }

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
    public function update(string $slug, array $input, User $actor, ?string $confirmToken = null): array
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
        $audit = AdminCommandAudit::create([
            'tenant_id' => (string) $tenant->slug,
            'user_id' => $actor->id,
            'command' => 'system-admin:tenant-update',
            'args_json' => [
                'tenant_slug' => $tenant->slug,
                'before' => $before,
                'after' => $changes,
                'surface' => 'http',
                'correlation_id' => $this->correlationId(),
                'confirm_token_present' => is_string($confirmToken) && $confirmToken !== '',
            ],
            'status' => AdminCommandAudit::STATUS_STARTED,
            'started_at' => now(),
            'client_ip' => request()?->ip(),
            'user_agent' => $this->userAgent(),
        ]);

        try {
            $updated = DB::transaction(function () use (
                $tenant,
                $changes,
                $actor,
                $confirmToken,
                $audit,
            ): Tenant {
                $lockedTenant = Tenant::query()
                    ->where('slug', $tenant->slug)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (
                    array_key_exists('status', $changes)
                    && $changes['status'] !== $lockedTenant->status
                ) {
                    $this->consumeLifecycleConfirmation(
                        (string) $lockedTenant->slug,
                        (string) $lockedTenant->status,
                        (string) $changes['status'],
                        $actor,
                        $confirmToken,
                    );
                }

                $lockedTenant->update($changes);

                $audit->forceFill([
                    'status' => AdminCommandAudit::STATUS_COMPLETED,
                    'exit_code' => 0,
                    'completed_at' => now(),
                ])->save();

                return $lockedTenant->fresh();
            });

            Log::notice('Tenant registry updated by system administrator.', [
                'actor_user_id' => $actor->id,
                'tenant_id' => $tenant->slug,
                'before' => $before,
                'after' => $changes,
            ]);
        } catch (ValidationException $e) {
            $audit->forceFill([
                'status' => AdminCommandAudit::STATUS_REJECTED,
                'exit_code' => 1,
                'error_message' => 'Lifecycle confirmation rejected.',
                'completed_at' => now(),
            ])->save();

            throw $e;
        } catch (\Throwable $e) {
            $audit->forceFill([
                'status' => AdminCommandAudit::STATUS_FAILED,
                'exit_code' => 1,
                'error_message' => mb_substr($e->getMessage(), 0, 1000),
                'completed_at' => now(),
            ])->save();

            throw $e;
        }

        return $this->payload($updated);
    }

    /**
     * Issue a one-time token bound to the exact tenant and lifecycle
     * transition. The plaintext token is returned once and never persisted.
     *
     * @return array<string,mixed>
     */
    public function previewLifecycle(string $slug, string $status, User $actor): array
    {
        $this->assertRegistryAvailable();
        $tenant = $this->tenantOrFail($slug);
        $status = trim($status);
        if (! in_array($status, self::STATUSES, true)) {
            throw ValidationException::withMessages([
                'status' => ['The status must be active, suspended, or archived.'],
            ]);
        }
        if ($status === $tenant->status) {
            throw ValidationException::withMessages([
                'status' => ['The tenant already has the requested status.'],
            ]);
        }

        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $expiresAt = now()->addMinutes(self::CONFIRM_TTL_MINUTES);

        AdminCommandNonce::create([
            'tenant_id' => (string) $tenant->slug,
            'token_hash' => hash('sha256', $token),
            'command' => self::LIFECYCLE_COMMAND,
            'user_id' => $actor->id,
            'args_hash' => $this->lifecycleHash(
                (string) $tenant->slug,
                (string) $tenant->status,
                $status,
            ),
            'created_at' => now(),
            'expires_at' => $expiresAt,
            'used_at' => null,
        ]);

        return [
            'tenant' => [
                'slug' => $tenant->slug,
                'name' => $tenant->name,
                'project_count' => (int) DB::table('projects')->where('tenant_id', $tenant->slug)->count(),
                'member_count' => (int) DB::table('project_memberships')
                    ->where('tenant_id', $tenant->slug)
                    ->distinct()
                    ->count('user_id'),
            ],
            'transition' => [
                'from' => $tenant->status,
                'to' => $status,
            ],
            'confirm_token' => $token,
            'confirm_token_expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    private function tenantOrFail(string $slug): Tenant
    {
        $query = Tenant::query()->where('slug', Str::lower(trim($slug)));
        if (Schema::hasColumn('tenants', 'is_system')) {
            $query->where('is_system', false);
        }
        $tenant = $query->first();
        if ($tenant === null) {
            throw new NotFoundHttpException('Tenant not found.');
        }

        return $tenant;
    }

    private function consumeLifecycleConfirmation(
        string $slug,
        string $from,
        string $to,
        User $actor,
        ?string $confirmToken,
    ): void {
        if (! is_string($confirmToken) || $confirmToken === '') {
            throw ValidationException::withMessages([
                'confirm_token' => ['A lifecycle confirmation token is required.'],
            ]);
        }

        $nonce = AdminCommandNonce::query()
            ->forTenant($slug)
            ->where('token_hash', hash('sha256', $confirmToken))
            ->lockForUpdate()
            ->first();

        $valid = $nonce !== null
            && $nonce->command === self::LIFECYCLE_COMMAND
            && (int) $nonce->user_id === (int) $actor->id
            && ! $nonce->isUsed()
            && ! $nonce->isExpired()
            && hash_equals($nonce->args_hash, $this->lifecycleHash($slug, $from, $to));

        if (! $valid) {
            throw ValidationException::withMessages([
                'confirm_token' => ['The lifecycle confirmation token is invalid, expired, used, or does not match this transition.'],
            ]);
        }

        $nonce->used_at = now();
        $nonce->save();
    }

    private function lifecycleHash(string $slug, string $from, string $to): string
    {
        return hash('sha256', json_encode([
            'tenant_slug' => $slug,
            'from' => $from,
            'to' => $to,
        ], JSON_THROW_ON_ERROR));
    }

    private function correlationId(): ?string
    {
        $value = request()?->header('X-Request-Id');

        return is_string($value) && $value !== '' ? mb_substr($value, 0, 120) : null;
    }

    private function userAgent(): ?string
    {
        $value = request()?->userAgent();

        return is_string($value) && $value !== '' ? mb_substr($value, 0, 255) : null;
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

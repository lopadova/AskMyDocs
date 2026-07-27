<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * One-step company provisioning shared by the super-admin HTTP control plane
 * and `company:create`.
 *
 * Tenant, project, identity, global role and project membership are committed
 * in one transaction. Existing active identities can be attached explicitly;
 * deleted/inactive identities are never silently revived and passwords are
 * never overwritten.
 */
final class TenantProvisioningService
{
    public function __construct(private readonly TeamRegistryService $teams) {}

    /**
     * Non-mutating preflight used by the UI to collapse "create user" and
     * "attach existing user" into a single fast form.
     *
     * @return array{
     *   tenant: array{slug: string, available: bool},
     *   user: array{status: string, email: string, id: int|null, name: string|null, roles: list<string>},
     *   can_provision: bool
     * }
     */
    public function availability(
        string $tenantName,
        ?string $tenantSlug,
        string $email,
        bool $requireRegistry = true,
    ): array {
        $email = $this->validatedEmail($email);
        $tenant = $this->teams->newTeamAvailability($tenantSlug, $tenantName, $requireRegistry);
        $user = $this->findUserByEmail($email);

        $userStatus = match (true) {
            $user === null => 'new',
            $user->trashed() => 'deleted',
            ! $user->is_active => 'inactive',
            default => 'existing',
        };

        return [
            'tenant' => $tenant,
            'user' => [
                'status' => $userStatus,
                'email' => $email,
                'id' => $user?->id,
                'name' => $user?->name,
                'roles' => $user?->getRoleNames()->values()->all() ?? [],
            ],
            'can_provision' => $tenant['available'] && in_array($userStatus, ['new', 'existing'], true),
        ];
    }

    /**
     * @param array{
     *   tenant_name: string,
     *   tenant_slug?: string|null,
     *   user_email: string,
     *   user_name?: string|null,
     *   password?: string|null,
     *   role?: string|null,
     *   attach_existing?: bool,
     *   project_key?: string|null,
     *   membership_role?: string|null
     * } $input
     * @param list<string>|null $allowedRoles null means any existing web role (CLI operator surface)
     * @return array{tenant: array<string,mixed>, project: array<string,mixed>, user: array<string,mixed>, attached_existing: bool, registry_created: bool}
     */
    public function provision(
        array $input,
        ?User $actor = null,
        ?array $allowedRoles = ['admin', 'editor', 'viewer'],
        bool $requireRegistry = true,
    ): array {
        $tenantName = trim((string) ($input['tenant_name'] ?? ''));
        $tenantSlug = isset($input['tenant_slug']) ? (string) $input['tenant_slug'] : null;
        $email = $this->validatedEmail((string) ($input['user_email'] ?? ''));
        $role = trim((string) ($input['role'] ?? '')) ?: 'admin';
        $attachExisting = (bool) ($input['attach_existing'] ?? false);

        $this->assertRole($role, $allowedRoles);

        $preflight = $this->availability($tenantName, $tenantSlug, $email, $requireRegistry);
        if (! $preflight['tenant']['available']) {
            throw ValidationException::withMessages([
                'tenant_slug' => ["A tenant with the slug '{$preflight['tenant']['slug']}' already exists."],
            ]);
        }

        $existing = $this->findUserByEmail($email);
        if ($existing?->trashed()) {
            throw ValidationException::withMessages([
                'user_email' => ['This email belongs to a deleted account. Restore that account before associating it.'],
            ]);
        }
        if ($existing !== null && ! $existing->is_active) {
            throw ValidationException::withMessages([
                'user_email' => ['This email belongs to an inactive account. Activate it before associating it.'],
            ]);
        }
        if ($existing !== null && ! $attachExisting) {
            throw ValidationException::withMessages([
                'user_email' => ['This user already exists. Confirm that you want to associate the existing account.'],
            ]);
        }
        if ($existing === null && $attachExisting) {
            throw ValidationException::withMessages([
                'user_email' => ['The account no longer exists. Create it as a new user instead.'],
            ]);
        }

        $userName = trim((string) ($input['user_name'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        if ($existing === null) {
            if ($userName === '') {
                throw ValidationException::withMessages(['user_name' => ['The user name is required for a new account.']]);
            }
            if (mb_strlen($userName) > 255) {
                throw ValidationException::withMessages(['user_name' => ['The user name may not be greater than 255 characters.']]);
            }
            if (mb_strlen($password) < 8) {
                throw ValidationException::withMessages(['password' => ['The password must be at least 8 characters.']]);
            }
        }

        $slug = $preflight['tenant']['slug'];
        $projectKey = trim((string) ($input['project_key'] ?? '')) ?: $slug;
        $membershipRole = trim((string) ($input['membership_role'] ?? ''))
            ?: ($role === 'admin' || $role === 'super-admin' ? 'admin' : 'member');

        try {
            $result = DB::transaction(function () use (
                $existing,
                $userName,
                $email,
                $password,
                $role,
                $slug,
                $tenantName,
                $projectKey,
                $membershipRole,
                $requireRegistry,
            ): array {
                $user = $existing ?? User::create([
                    'name' => $userName,
                    'email' => $email,
                    'password' => $password,
                    'is_active' => true,
                ]);

                // GRANT-never-revoke for an existing identity: provisioning a
                // tenant must not remove roles used by their other tenants.
                $user->assignRole($role);

                $tenant = $this->teams->createForMember(
                    $slug,
                    $tenantName,
                    $user,
                    $projectKey,
                    $membershipRole,
                    $requireRegistry,
                );

                return ['user' => $user->fresh(), 'tenant' => $tenant];
            });
        } catch (QueryException $e) {
            // A concurrent request can win after preflight. Convert the known
            // uniqueness races into stable field errors; rethrow unrelated DB
            // failures so they are never misreported as a duplicate.
            $errors = [];
            if (! $this->teams->newTeamAvailability($slug, $tenantName, $requireRegistry)['available']) {
                $errors['tenant_slug'] = ["A tenant with the slug '{$slug}' already exists."];
            }
            if ($this->findUserByEmail($email) !== null) {
                $errors['user_email'] = ['This email is already registered. Re-run the check to associate the existing account.'];
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            throw $e;
        }

        /** @var User $user */
        $user = $result['user'];
        $registryCreated = Schema::hasTable('tenants');

        Log::notice('Tenant provisioned.', [
            'actor_user_id' => $actor?->id,
            'tenant_id' => $slug,
            'target_user_id' => $user->id,
            'attached_existing' => $existing !== null,
            'role' => $role,
            'project_key' => $projectKey,
        ]);

        return [
            'tenant' => $result['tenant'],
            'project' => [
                'project_key' => $projectKey,
                'name' => $tenantName,
                'membership_role' => $membershipRole,
            ],
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_active' => (bool) $user->is_active,
                'roles' => $user->getRoleNames()->values()->all(),
            ],
            'attached_existing' => $existing !== null,
            'registry_created' => $registryCreated,
        ];
    }

    private function validatedEmail(string $email): string
    {
        $email = User::normalizeEmail($email);
        if ($email === '' || mb_strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw ValidationException::withMessages(['user_email' => ['Enter a valid email address.']]);
        }

        return $email;
    }

    /**
     * @param list<string>|null $allowedRoles
     */
    private function assertRole(string $role, ?array $allowedRoles): void
    {
        if ($allowedRoles !== null && ! in_array($role, $allowedRoles, true)) {
            throw ValidationException::withMessages([
                'role' => ['The role must be one of: '.implode(', ', $allowedRoles).'.'],
            ]);
        }

        if (! Role::query()->where('name', $role)->where('guard_name', 'web')->exists()) {
            throw ValidationException::withMessages([
                'role' => ["Role '{$role}' was not found on the web guard."],
            ]);
        }
    }

    private function findUserByEmail(string $email): ?User
    {
        $query = User::withTrashed()->with('roles');

        if (Schema::hasColumn('users', 'email_normalized')) {
            return $query->where('email_normalized', $email)->first();
        }

        return $query->whereRaw('LOWER(email) = ?', [Str::lower($email)])->first();
    }
}

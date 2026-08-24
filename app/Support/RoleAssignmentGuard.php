<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Role-assignment privilege ceiling (security review v8.8 — vertical
 * privilege-escalation fix).
 *
 * The admin Users CRUD is reachable by the `admin` role (route gate is
 * `role:admin|super-admin`). The store/update FormRequests previously
 * validated assigned roles only with `Rule::exists('roles','name')`, so an
 * `admin` could `syncRoles(['super-admin'])` on any account (including their
 * own) and inherit tenant-level destructive capabilities deliberately
 * withheld from `admin` (see RbacSeeder).
 *
 * Invariant enforced here: a user may only assign a role whose permission
 * set is fully contained in their OWN effective permissions. A tenant
 * super-admin holds every tenant permission, so it can grant `super-admin`;
 * an `admin` can still grant `viewer`/`editor` (subsets of its grants) but
 * not a role carrying a permission it lacks. This is a strict
 * "no-privilege-amplification" ceiling rather than a hard-coded role list,
 * so it also blocks lateral escalation to capabilities the actor does not
 * possess (e.g. `admin` → `dpo`'s `pii.detokenize`).
 */
final class RoleAssignmentGuard
{
    /**
     * Return the subset of $requestedRoles the actor is NOT allowed to
     * assign because the role carries at least one permission the actor
     * does not hold.
     *
     * @param  array<int,mixed>  $requestedRoles
     * @return array<int,string>
     */
    public static function disallowedRoles(?User $actor, array $requestedRoles): array
    {
        $names = array_values(array_unique(array_filter(
            $requestedRoles,
            static fn ($role): bool => is_string($role) && $role !== '',
        )));

        if ($actor === null || $names === []) {
            return [];
        }

        // The platform role is never managed through tenant-facing Users CRUD,
        // even by another system administrator. Grant/revoke has its own
        // audited CLI workflow and last-system-admin protections.
        if (in_array(PlatformAccess::SYSTEM_ADMIN_ROLE, $names, true)) {
            $disallowed = [PlatformAccess::SYSTEM_ADMIN_ROLE];
        } else {
            $disallowed = [];
        }

        $actorPermissions = array_flip(
            $actor->getAllPermissions()->pluck('name')->all()
        );

        $roles = Role::query()
            ->whereIn('name', $names)
            ->with('permissions:id,name')
            ->get()
            ->keyBy('name');

        foreach ($names as $name) {
            if ($name === PlatformAccess::SYSTEM_ADMIN_ROLE) {
                continue;
            }

            $role = $roles->get($name);
            if ($role === null) {
                // Unknown role — leave rejection to the `exists` rule.
                continue;
            }

            foreach ($role->permissions as $permission) {
                if (! isset($actorPermissions[$permission->name])) {
                    $disallowed[] = $name;
                    break;
                }
            }
        }

        return array_values(array_unique($disallowed));
    }
}

<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\Permission\Models\Role;

/**
 * @property-read Role $resource
 */
class RoleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Role $role */
        $role = $this->resource;

        return [
            'id' => $role->id,
            'name' => $role->name,
            'guard_name' => $role->guard_name,
            'permissions' => $role->permissions->pluck('name')->values(),
            // Users_count is loaded via withCount('users') in the index path;
            // pull it lazily so show() doesn't force a stale 0.
            'users_count' => (int) ($role->users_count ?? $role->users()->count()),
            'created_at' => optional($role->created_at)->toIso8601String(),
            'updated_at' => optional($role->updated_at)->toIso8601String(),
        ];
    }
}

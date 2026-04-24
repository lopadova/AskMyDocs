<?php

namespace App\Http\Resources\Admin;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin-facing JSON shape for the User model.
 *
 * Deliberately omits `password`, `remember_token`, and `two_factor_secret`
 * (which exists on this model once Phase B2 lands). The admin UI renders
 * roles + active + trashed state; anything beyond that is leaked surface.
 *
 * @property-read User $resource
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'email_verified_at' => optional($user->email_verified_at)->toIso8601String(),
            'is_active' => (bool) $user->is_active,
            'deleted_at' => optional($user->deleted_at)->toIso8601String(),
            'created_at' => optional($user->created_at)->toIso8601String(),
            'updated_at' => optional($user->updated_at)->toIso8601String(),
            'roles' => $user->getRoleNames()->values(),
            'permissions' => $user->getAllPermissions()->pluck('name')->values(),
        ];
    }
}

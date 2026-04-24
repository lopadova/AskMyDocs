<?php

namespace App\Http\Resources\Admin;

use App\Models\ProjectMembership;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read ProjectMembership $resource
 */
class MembershipResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ProjectMembership $m */
        $m = $this->resource;

        return [
            'id' => $m->id,
            'user_id' => $m->user_id,
            'project_key' => $m->project_key,
            'role' => $m->role,
            'scope_allowlist' => $m->scope_allowlist,
            'created_at' => optional($m->created_at)->toIso8601String(),
            'updated_at' => optional($m->updated_at)->toIso8601String(),
        ];
    }
}

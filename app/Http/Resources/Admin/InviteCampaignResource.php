<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\InviteCampaign;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin InviteCampaign
 */
class InviteCampaignResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'key' => $this->resource->key,
            'name' => $this->resource->name,
            'description' => $this->resource->description,
            'type' => $this->resource->type,
            'status' => $this->resource->status,
            'max_redemptions_total' => $this->resource->max_redemptions_total,
            'per_user_limit' => $this->resource->per_user_limit,
            'starts_at' => optional($this->resource->starts_at)->toIso8601String(),
            'ends_at' => optional($this->resource->ends_at)->toIso8601String(),
            'reward_policy' => $this->resource->reward_policy,
            'grant' => $this->resource->grant,
            'created_by' => $this->resource->created_by,
            'created_at' => optional($this->resource->created_at)->toIso8601String(),
            'updated_at' => optional($this->resource->updated_at)->toIso8601String(),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\InviteCode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin InviteCode
 *
 * `signature` is intentionally omitted — a signed code's MAC is a secret and
 * is never surfaced once the full code string has been delivered.
 */
class InviteCodeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'campaign_id' => $this->resource->campaign_id,
            'code' => $this->resource->code,
            'code_kind' => $this->resource->code_kind,
            'state' => $this->resource->state,
            'max_uses' => $this->resource->max_uses,
            'current_uses' => $this->resource->current_uses,
            'issuer_id' => $this->resource->issuer_id,
            'expires_at' => optional($this->resource->expires_at)->toIso8601String(),
            'created_at' => optional($this->resource->created_at)->toIso8601String(),
        ];
    }
}

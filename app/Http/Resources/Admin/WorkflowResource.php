<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\Workflow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Workflow
 */
class WorkflowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'user_id' => $this->user_id,
            'title' => $this->title,
            'type' => $this->type,
            'prompt_md' => $this->prompt_md,
            'columns_config' => $this->columns_config,
            'practice' => $this->practice,
            'is_system' => $this->is_system,
            'created_at' => optional($this->created_at)?->toIso8601String(),
            'updated_at' => optional($this->updated_at)?->toIso8601String(),
            // Copilot iter 3: emit `shares` as a plain array of
            // resolved payloads. Returning
            // `WorkflowShareResource::collection($this->whenLoaded('shares'))`
            // would emit `shares: {data: [...]}` (nested envelope) when
            // serialised through `response()->json()`. The controller
            // test asserts `data.shares.0.shared_with_email`, which
            // only works against the flat-array shape.
            'shares' => $this->whenLoaded('shares', function () use ($request): array {
                return $this->shares
                    ->map(fn ($share) => (new WorkflowShareResource($share))->resolve($request))
                    ->all();
            }, []),
        ];
    }
}

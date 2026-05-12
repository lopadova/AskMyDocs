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
            'shares' => WorkflowShareResource::collection($this->whenLoaded('shares')),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\WorkflowShare;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin WorkflowShare
 */
class WorkflowShareResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workflow_id' => $this->workflow_id,
            'shared_by_user_id' => $this->shared_by_user_id,
            'shared_with_email' => $this->shared_with_email,
            'allow_edit' => $this->allow_edit,
            'created_at' => optional($this->created_at)?->toIso8601String(),
            'updated_at' => optional($this->updated_at)?->toIso8601String(),
        ];
    }
}

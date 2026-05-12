<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\TabularReview;

use App\Support\TabularReview\FormatType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * v4.7/W1 — PATCH /api/admin/tabular-reviews/{id}.
 *
 * Only title / columns_config / shared_with / practice / workflow_id
 * are updatable. project_key is immutable — moving a review across
 * projects would orphan its cells whose KnowledgeDocument FK is
 * tenant-scoped per-project, so we reject it explicitly via 422 in
 * the controller.
 */
class UpdateTabularReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:200'],
            'columns_config' => ['sometimes', 'array', 'min:1', 'max:50'],
            'columns_config.*.name' => ['required_with:columns_config', 'string', 'max:120'],
            'columns_config.*.prompt' => ['nullable', 'string', 'max:2000'],
            'columns_config.*.format' => ['required_with:columns_config', 'string', Rule::in(FormatType::values())],
            'columns_config.*.enum_values' => ['nullable', 'array', 'max:100'],
            'columns_config.*.enum_values.*' => ['string', 'max:120'],
            'columns_config.*.json_path' => ['nullable', 'string', 'max:200'],
            'workflow_id' => ['sometimes', 'nullable', 'integer'],
            'shared_with' => ['sometimes', 'nullable', 'array'],
            'shared_with.*' => ['integer'],
            'practice' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }
}

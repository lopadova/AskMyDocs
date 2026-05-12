<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\TabularReview;

use App\Support\TabularReview\FormatType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * v4.7/W1 — POST /api/admin/tabular-reviews — admin creates a review.
 *
 * Authorisation is enforced at the route layer via the `viewTabularReviews`
 * gate. This form only validates the wire shape and the columns_config
 * payload — name + prompt + format + optional enum_values / json_path.
 */
class StoreTabularReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'project_key' => ['required', 'string', 'max:120'],
            'title' => ['required', 'string', 'max:200'],
            'columns_config' => ['required', 'array', 'min:1', 'max:50'],
            'columns_config.*.name' => ['required', 'string', 'max:120'],
            'columns_config.*.prompt' => ['nullable', 'string', 'max:2000'],
            'columns_config.*.format' => ['required', 'string', Rule::in(FormatType::values())],
            'columns_config.*.enum_values' => ['nullable', 'array', 'max:100'],
            'columns_config.*.enum_values.*' => ['string', 'max:120'],
            'columns_config.*.json_path' => ['nullable', 'string', 'max:200'],
            'workflow_id' => ['nullable', 'integer'],
            'shared_with' => ['nullable', 'array'],
            'shared_with.*' => ['integer'],
            'practice' => ['nullable', 'string', 'max:100'],
        ];
    }
}

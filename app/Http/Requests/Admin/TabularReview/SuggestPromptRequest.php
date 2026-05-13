<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\TabularReview;

use App\Support\TabularReview\FormatType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * v4.7/W1 — POST /api/admin/tabular-reviews/prompt.
 *
 * Body: { column_name, format } → returns { prompt: "..." }.
 * Powers the inline-editor "Auto-generate prompt" button.
 */
class SuggestPromptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'column_name' => ['required', 'string', 'max:120'],
            'format' => ['required', 'string', Rule::in(FormatType::values())],
        ];
    }
}

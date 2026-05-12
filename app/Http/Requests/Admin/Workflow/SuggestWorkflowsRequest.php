<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Workflow;

use Illuminate\Foundation\Http\FormRequest;

class SuggestWorkflowsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'limit' => ['nullable', 'integer', 'min:1', 'max:10'],
            'force_refresh' => ['nullable', 'boolean'],
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Workflow;

use App\Support\TabularReview\FormatType;
use App\Support\Workflow\WorkflowPractice;
use App\Support\Workflow\WorkflowType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWorkflowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'type' => ['required', 'string', Rule::in(WorkflowType::values())],
            'prompt_md' => ['required', 'string', 'max:20000'],
            'practice' => ['nullable', 'string', Rule::in(WorkflowPractice::values())],

            'columns_config' => [
                'nullable',
                'required_if:type,tabular',
                'array',
                'min:1',
                'max:50',
            ],
            'columns_config.*.name' => ['required_with:columns_config', 'string', 'max:120'],
            'columns_config.*.prompt' => ['nullable', 'string', 'max:2000'],
            'columns_config.*.format' => [
                'required_with:columns_config',
                'string',
                Rule::in(FormatType::values()),
            ],
            'columns_config.*.enum_values' => ['nullable', 'array', 'max:100'],
            'columns_config.*.enum_values.*' => ['string', 'max:120'],
            'columns_config.*.json_path' => [
                'required_if:columns_config.*.format,json_path',
                'nullable',
                'string',
                'max:200',
            ],
        ];
    }
}

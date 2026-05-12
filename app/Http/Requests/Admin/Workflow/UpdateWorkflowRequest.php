<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Workflow;

use App\Support\TabularReview\FormatType;
use App\Support\Workflow\WorkflowPractice;
use App\Support\Workflow\WorkflowType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWorkflowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:200'],
            'type' => ['sometimes', 'string', Rule::in(WorkflowType::values())],
            'prompt_md' => ['sometimes', 'string', 'max:20000'],
            // Copilot iter 4: `workflows.practice` is a NOT NULL column
            // with default 'generic'. Removing `nullable` here so a
            // client cannot send `practice: null` and 500 the request
            // when `fill() + save()` propagates NULL.
            'practice' => ['sometimes', 'string', Rule::in(WorkflowPractice::values())],

            // Copilot iter 5/13/16: `columns_config` is required
            // when the request sets `type=tabular`. iter 16 flagged
            // that `nullable` previously let `{columns_config: null}`
            // pass when `type` was omitted — but if the existing
            // workflow is tabular, the effective type after the
            // patch stays tabular and the service-layer
            // normaliseColumnsConfig would 500. Dropping `nullable`
            // so any explicit-null value is rejected at 422; omitting
            // the field entirely is still fine (the rule chain is a
            // no-op when the key isn't present). The
            // assistant-direction patch (type=assistant) lets the
            // service clear `columns_config` regardless.
            'columns_config' => [
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

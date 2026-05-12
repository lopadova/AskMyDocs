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

            // Copilot iter 5/13: `columns_config` is required when
            // the request sets `type=tabular`. Copilot iter 13
            // flagged that combining `sometimes` + `required_if` is
            // contradictory — `sometimes` skips validation when the
            // field is absent, which would let
            // `{type: 'tabular'}` (no columns_config) pass and then
            // trigger the service-layer InvalidArgumentException at
            // 500. Dropping `sometimes` so `required_if` fires
            // whenever `type=tabular` is present; the field is
            // ABSENT iff the caller is patching only `type=assistant`
            // or unrelated fields, in which case `required_if` does
            // not apply and the rule chain is a no-op (no `sometimes`
            // bypass needed — the absence itself satisfies the
            // optional-conditional contract).
            'columns_config' => [
                'required_if:type,tabular',
                'nullable',
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

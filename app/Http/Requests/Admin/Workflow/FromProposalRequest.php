<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Workflow;

use App\Support\TabularReview\FormatType;
use App\Support\Workflow\WorkflowPractice;
use App\Support\Workflow\WorkflowType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FromProposalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'proposal' => ['required', 'array'],
            'proposal.title' => ['required', 'string', 'max:200'],
            'proposal.type' => ['required', 'string', Rule::in(WorkflowType::values())],
            'proposal.prompt_md' => ['required', 'string', 'max:20000'],
            'proposal.practice' => ['nullable', 'string', Rule::in(WorkflowPractice::values())],
            'proposal.columns_config' => [
                'nullable',
                'required_if:proposal.type,tabular',
                'array',
                'min:1',
                'max:50',
            ],
            'proposal.columns_config.*.name' => ['required_with:proposal.columns_config', 'string', 'max:120'],
            'proposal.columns_config.*.prompt' => ['nullable', 'string', 'max:2000'],
            'proposal.columns_config.*.format' => [
                'required_with:proposal.columns_config',
                'string',
                Rule::in(FormatType::values()),
            ],
            'proposal.columns_config.*.enum_values' => ['nullable', 'array', 'max:100'],
            'proposal.columns_config.*.enum_values.*' => ['string', 'max:120'],
            // Copilot iter 1: align with Store/UpdateWorkflowRequest —
            // json_path is required when the column format is the
            // LLM-free `json_path` shortcut; otherwise the extractor
            // has nothing to look up and every cell silently falls
            // back to red (R14).
            'proposal.columns_config.*.json_path' => [
                'required_if:proposal.columns_config.*.format,json_path',
                'nullable',
                'string',
                'max:200',
            ],
        ];
    }
}

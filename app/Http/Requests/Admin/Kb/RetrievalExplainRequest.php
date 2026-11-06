<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Kb;

use Illuminate\Foundation\Http\FormRequest;

/**
 * v8.41/W1 — validates `GET /api/admin/kb/retrieval/explain`. Authorization is
 * enforced by the route group (`auth:sanctum` + `tenant.authorize` +
 * `role:admin|super-admin`).
 */
final class RetrievalExplainRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'query' => ['required', 'string', 'min:1', 'max:500'],
            'project_key' => ['nullable', 'string', 'max:120'],
            'intent' => ['nullable', 'string', 'max:300'],
            'investigation' => ['nullable', 'boolean'],
            'min_score' => ['nullable', 'numeric'],
            'line_numbers' => ['nullable', 'boolean'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.max(1, (int) config('kb.retrieval_explain.max_limit', 20))],
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * v8.8/W3 — validate an upsert of a per-(tenant, project) deep-analysis
 * override. `project_key='*'` is the tenant-wide default. Every flag is
 * nullable: a null value CLEARS the override for that field (the gate then
 * inherits the next level up).
 */
final class UpsertAnalysisSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route-group middleware (auth:sanctum + role:admin|super-admin)
        // already gates access; nothing per-row to authorize here.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'project_key' => ['required', 'string', 'max:120'],
            'enabled' => ['nullable', 'boolean'],
            'canonical' => ['nullable', 'boolean'],
            'non_canonical' => ['nullable', 'boolean'],
            'delete_enabled' => ['nullable', 'boolean'],
        ];
    }
}

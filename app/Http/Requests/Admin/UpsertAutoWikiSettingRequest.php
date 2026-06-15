<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * v8.11/P10 — upsert one (tenant, project) Auto-Wiki settings override. Each
 * flag is nullable: a null value CLEARS the override for that field (the gate
 * then inherits the tenant-wide '*' row, then the config default). Authorization
 * is enforced by the admin KB route group (role:admin|super-admin).
 */
final class UpsertAutoWikiSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'project_key' => ['required', 'string', 'max:120'],
            'autowiki_enabled' => ['nullable', 'boolean'],
            'autowiki_canonical' => ['nullable', 'boolean'],
            'autowiki_non_canonical' => ['nullable', 'boolean'],
        ];
    }
}

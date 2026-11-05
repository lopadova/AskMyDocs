<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Kb;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/admin/kb/exports — v8.38/W4b (ADR 0032 §11).
 *
 * `format`/`include_images` are accepted as optional fields so the request
 * shape does not have to change again in W4c, but {@see
 * \App\Services\Kb\Export\KbWikiExportRequestService::requestExport()}
 * rejects any value other than the one W4b actually implements
 * (`format: llm-wiki`, `include_images: false`) — R14: reject loudly rather
 * than silently ignore an option the export cannot honour yet.
 */
class StoreKbWikiExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        // RBAC is enforced by the route middleware (role:admin|super-admin).
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'project_key' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'format' => ['sometimes', 'string', 'max:40'],
            'include_images' => ['sometimes', 'boolean'],
        ];
    }
}

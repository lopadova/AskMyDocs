<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Kb;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/admin/kb/imports — v8.38/W4c (ADR 0032 §11).
 *
 * Single-DOCUMENT surface, deliberately: unlike the CLI (`kb:import-wiki
 * {folder}`), an HTTP client has no server-side filesystem folder to hand
 * over — it submits one page's markdown at a time, exactly the shape
 * `POST /api/kb/promotion/candidates` already validates against. See
 * {@see \App\Services\Kb\Import\KbWikiImportService::importDocument()}.
 */
class StoreKbWikiImportRequest extends FormRequest
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
            'markdown' => ['required', 'string', 'max:200000'],
        ];
    }
}

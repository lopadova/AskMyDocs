<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\KbPiiSetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * v8.23 (Ciclo 4) — validate an upsert of a per-(tenant, project) PII
 * ingestion-policy override. `project_key='*'` is the tenant-wide default.
 * Every override field is nullable: an explicit null CLEARS the override for
 * that field (the resolver then inherits the next level up); an omitted field
 * is left unchanged (partial update).
 */
final class UpsertKbPiiSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route-group middleware (auth:sanctum + can:manageKbPiiPolicy) already
        // gates access; nothing per-row to authorize here.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'project_key' => ['required', 'string', 'max:120'],
            'redact_enabled' => ['nullable', 'boolean'],
            // Only the two ingestion strategies are valid policy values; an
            // explicit null clears the override (inherit the config default).
            'strategy' => ['nullable', Rule::in(KbPiiSetting::STRATEGIES)],
        ];
    }
}

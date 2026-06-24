<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\KbPiiSetting;
use Illuminate\Contracts\Validation\Validator;
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

    /**
     * Reject a payload that carries ONLY `project_key`: with no mutable field
     * present the controller's partial update would `updateOrCreate` an all-NULL
     * override row (a no-op policy that is indistinguishable from "no row") —
     * almost certainly an accidental call. At least one of `redact_enabled` /
     * `strategy` must be present (an EXPLICIT null is allowed — that is the
     * documented clear-to-inherit gesture). Keyed on `array_key_exists` to match
     * the controller's partial-update detection (so explicit null counts as
     * present, unlike `filled()`/`has()` which treat null as absent).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $data = $this->all();
            if (! array_key_exists('redact_enabled', $data) && ! array_key_exists('strategy', $data)) {
                $validator->errors()->add(
                    'strategy',
                    'Provide at least one of redact_enabled or strategy (an explicit null clears the field).',
                );
            }
        });
    }
}

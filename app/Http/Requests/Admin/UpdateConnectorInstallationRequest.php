<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Services\Admin\Connectors\ConnectorSettingsService;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;

/**
 * v8.20 — validates a metadata edit of an existing connector account
 * (`PATCH /api/admin/connectors/{installationId}`): rename the `label` and/or
 * rebind `project_key`. PARTIAL — only present keys are validated/applied.
 *
 * v8.24 — also accepts the connection-settings the picker edits:
 * `folders.include` (array of EXACT, case-sensitive IMAP folder paths — the
 * sync whitelist; empty = sync all non-excluded folders) and
 * `date_window_days` (how far back to walk). Both land in `config_json` via a
 * read-modify-write in {@see \App\Services\Admin\Connectors\ConnectorInstallationService::updateMetadata}.
 *
 * v8.25 — also accepts the GENERIC `settings` object: a nested partial of
 * config_json validated DYNAMICALLY against the connector's
 * {@see \Padosoft\AskMyDocsConnectorBase\Contracts\SupportsConnectionSettings::connectionSettingsSchema()}
 * (each field's type → its rule; e.g. a `multiselect`/`tags` field → an array of
 * strings, a `number` → an integer). There is no connector-specific rule list
 * here (R23): any connector that advertises a settings schema validates for free.
 * The v8.24 `folders`/`date_window_days` keys stay for back-compat (R27).
 *
 * The `label` unique is scoped to (tenant, connector) and ignores the row
 * itself, so re-saving the same label is a no-op rather than a false collision.
 * Credential re-auth is out of scope (delete + re-add); this never touches the
 * vault. The connector name is derived from the row (the route only carries the
 * id); when the row is absent the controller's tenant-scoped lookup 404s.
 */
final class UpdateConnectorInstallationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // '' = inherit the tenant default → null so `nullable` short-circuits
        // the `exists` rule and the column is genuinely cleared.
        if ($this->input('project_key') === '') {
            $this->merge(['project_key' => null]);
        }

        // Normalize folders.include BEFORE validation: trim each path, drop blank
        // entries, dedupe. `distinct` does NOT trim, so without this a "  " or a
        // trailing-space duplicate would slip past. An explicit empty array is
        // preserved (it means "clear the whitelist → sync all non-excluded").
        $folders = $this->input('folders');
        if (is_array($folders) && array_key_exists('include', $folders)) {
            $include = is_array($folders['include']) ? $folders['include'] : [];
            $folders['include'] = $this->normalizeIncludePaths($include);
            $this->merge(['folders' => $folders]);
        }
    }

    /**
     * Trim + drop-blank + dedupe ONLY string entries, preserving any non-string
     * element verbatim so the `folders.include.* => string` rule can reject it
     * (422) instead of it being silently swallowed (R14). A manual loop is used
     * deliberately over `array_unique()`, which (default SORT_STRING) would emit
     * an "Array to string conversion" warning before validation if a nested
     * array/object slipped into the payload (R19).
     *
     * @param  array<int|string, mixed>  $include
     * @return list<mixed>
     */
    private function normalizeIncludePaths(array $include): array
    {
        $seen = [];
        $out = [];
        foreach ($include as $entry) {
            // null = a blank entry the global TrimStrings/ConvertEmptyStringsToNull
            // middleware already collapsed (e.g. "  ") — drop it silently, same as
            // a blank string. Other non-strings (int, nested array, bool) are KEPT
            // verbatim so the `folders.include.* => string` rule rejects them (422)
            // instead of being swallowed (R14).
            if ($entry === null) {
                continue;
            }
            if (! is_string($entry)) {
                $out[] = $entry; // keep for the validator to 422

                continue;
            }
            $trimmed = trim($entry);
            if ($trimmed === '' || isset($seen[$trimmed])) {
                continue;
            }
            $seen[$trimmed] = true;
            $out[] = $trimmed;
        }

        return $out;
    }

    /**
     * @return array<string, list<mixed>>
     */
    private ?ConnectorInstallation $installation = null;

    private bool $installationResolved = false;

    /** @var list<array<string,mixed>>|null */
    private ?array $settingsSchemaCache = null;

    /**
     * The tenant-scoped installation under edit, resolved once. Null for an
     * unknown / cross-tenant id (the controller's findOr404 turns that into a 404).
     */
    private function installation(): ?ConnectorInstallation
    {
        if (! $this->installationResolved) {
            $tenantId = app(TenantContext::class)->current();
            $id = (int) $this->route('installationId');
            $this->installation = ConnectorInstallation::query()
                ->where('id', $id)
                ->where('tenant_id', $tenantId)
                ->first();
            $this->installationResolved = true;
        }

        return $this->installation;
    }

    /**
     * The connector's settings schema, resolved once (reused by rules() +
     * withValidator() so the registry/connector isn't queried twice).
     *
     * @return list<array<string,mixed>>
     */
    private function settingsSchema(): array
    {
        if ($this->settingsSchemaCache === null) {
            $installation = $this->installation();
            $this->settingsSchemaCache = $installation === null
                ? []
                : app(ConnectorSettingsService::class)->schemaFor($installation);
        }

        return $this->settingsSchemaCache;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->current();
        $installation = $this->installation();

        if ($installation === null) {
            // Unknown / cross-tenant id — the controller's findOr404 turns this
            // into a 404. Nothing to validate.
            return [];
        }

        $rules = [
            'label' => [
                'sometimes', 'required', 'string', 'max:64',
                'regex:/^[\pL\pN][\pL\pN _.-]*$/u',
                Rule::unique('connector_installations', 'label')
                    ->where('tenant_id', $tenantId)
                    ->where('connector_name', $installation->connector_name)
                    ->ignore($installation->id),
            ],
            'project_key' => [
                'sometimes', 'nullable', 'string', 'max:120',
                Rule::exists('projects', 'project_key')->where('tenant_id', $tenantId),
            ],
            // v8.24 — connection settings the picker edits. `folders.include` is
            // the sync whitelist (exact, case-sensitive paths); an empty array is
            // valid and means "clear → sync all non-excluded folders".
            'folders' => ['sometimes', 'array'],
            'folders.include' => ['sometimes', 'array', 'max:200'],
            'folders.include.*' => ['string', 'distinct', 'min:1', 'max:255'],
            // nullable: an explicit null CLEARS the override back to the connector
            // default (the service unsets the config_json key); an omitted key is
            // left unchanged (PATCH). max bound matches the schema-driven
            // `settings.date_window_days` rule (the same config field) so a value
            // can't be accepted on one surface and rejected on the other (R44).
            'date_window_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000'],
            // v8.25 — the generic settings object (a nested partial of config_json).
            'settings' => ['sometimes', 'array'],
        ];

        // Derive a rule per settings field from the connector's own schema (R23 —
        // no connector-name branch). The field `name` is a dotted path, so the
        // rule key nests under `settings` (e.g. 'settings.folders.include').
        foreach ($this->settingsSchema() as $field) {
            $name = (string) ($field['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $rules += $this->settingsFieldRules('settings.'.$name, $field);
        }

        return $rules;
    }

    /**
     * Reject an unknown nested `settings` key (e.g. a typo like
     * `settings.date_window_day`). Laravel includes unruled nested keys in the
     * validated payload, and {@see ConnectorSettingsService::mergeIntoConfig} only
     * writes schema-declared paths — so without this an operator typo would 200-OK
     * yet silently do nothing (R14). A key is accepted ONLY when it equals a schema
     * field's dotted name or is a descendant of one (a list element like
     * `folders.include.0`). An ancestor/container key (`folders` for a
     * `folders.include` field, or a scalar mis-shaped `folders: "x"`) is REJECTED —
     * mergeIntoConfig would ignore it, so accepting it would re-open the silent
     * no-op path this guard exists to close.
     */
    public function withValidator(Validator $validator): void
    {
        if ($this->installation() === null) {
            return;
        }
        $settings = $this->input('settings');
        if (! is_array($settings)) {
            return;
        }

        $known = [];
        foreach ($this->settingsSchema() as $field) {
            $name = (string) ($field['name'] ?? '');
            if ($name !== '') {
                $known[] = $name;
            }
        }

        $validator->after(function (Validator $validator) use ($settings, $known): void {
            foreach (Arr::dot($settings) as $key => $value) {
                $key = (string) $key;
                $covered = false;
                foreach ($known as $name) {
                    if ($key === $name || str_starts_with($key, $name.'.')) {
                        $covered = true;
                        break;
                    }
                }
                if (! $covered) {
                    $validator->errors()->add("settings.{$key}", "Unknown setting '{$key}' for this connector.");
                }
            }
        });
    }

    /**
     * Validation rules for one settings field, keyed by its nested path under
     * `settings`. A list field (multiselect/tags) also gets a `.*` element rule.
     *
     * @param  array<string,mixed>  $field
     * @return array<string, list<mixed>>
     */
    private function settingsFieldRules(string $key, array $field): array
    {
        return match ((string) ($field['type'] ?? 'text')) {
            'multiselect', 'tags' => [
                // nullable: a present null clears the override back to the connector
                // default (mergeIntoConfig unsets the key), matching the scalar
                // fields — without it a list couldn't be reverted to default.
                $key => ['sometimes', 'nullable', 'array', 'max:500'],
                // distinct + min:1: a list field must not carry duplicates or
                // empty-string entries (matches the v8.24 folders.include.* rule) —
                // redundant/blank entries cause wasted connector work and dirty
                // config_json.
                $key.'.*' => ['string', 'distinct', 'min:1', 'max:255'],
            ],
            // nullable: an explicit null clears the override back to the connector
            // default (the UI sends null when a number field is emptied).
            'number' => [$key => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000']],
            'checkbox' => [$key => ['sometimes', 'boolean']],
            // nullable: an empty value (UI/CLI clear → null via middleware) reverts
            // the override to the connector default instead of 422-ing on Rule::in.
            'select' => [$key => ['sometimes', 'nullable', Rule::in(array_keys((array) ($field['options'] ?? [])))]],
            default => [$key => ['sometimes', 'string', 'max:2000']],
        };
    }
}

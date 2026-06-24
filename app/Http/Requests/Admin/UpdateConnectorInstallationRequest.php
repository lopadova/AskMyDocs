<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
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
            // Trim strings + drop blank entries (the global TrimStrings /
            // ConvertEmptyStringsToNull middleware already turns "  " into null) +
            // dedupe. Other non-string entries are deliberately KEPT so the
            // `folders.include.* => string` rule rejects them (422) instead of
            // being silently swallowed here (R14).
            $folders['include'] = array_values(array_unique(array_filter(
                array_map(static fn ($v) => is_string($v) ? trim($v) : $v, $include),
                static fn ($v) => $v !== '' && $v !== null,
            )));
            $this->merge(['folders' => $folders]);
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->current();
        $id = (int) $this->route('installationId');

        $installation = ConnectorInstallation::query()
            ->where('id', $id)
            ->where('tenant_id', $tenantId)
            ->first();

        if ($installation === null) {
            // Unknown / cross-tenant id — the controller's findOr404 turns this
            // into a 404. Nothing to validate.
            return [];
        }

        return [
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
            'date_window_days' => ['sometimes', 'integer', 'min:0', 'max:3650'],
        ];
    }
}

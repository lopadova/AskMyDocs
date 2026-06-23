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
        ];
    }
}

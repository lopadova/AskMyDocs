<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * v8.20 — validates the OAuth-connector install entry point
 * (`GET /api/admin/connectors/{name}/install`) for multi-account.
 *
 * `label` selects WHICH account to (re-)grant: an existing (tenant, connector,
 * label) is re-armed; a new label starts a fresh account. It is therefore NOT
 * unique-validated here (re-grant is idempotent by label) — only shape-checked.
 * `project_key` binds the account to a real tenant project (R18); blank/absent
 * inherits the tenant default and, on a re-grant, leaves an existing binding
 * untouched (the controller only writes it when present).
 *
 * Authorization is the route group (`auth:sanctum` + `tenant.authorize` +
 * `can:manageConnectors`).
 */
final class StartConnectorInstallRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        // Default an omitted/blank label to 'default' (the column default),
        // preserving the single-account install flow for callers that send none.
        if ($this->input('label') === null || $this->input('label') === '') {
            $merge['label'] = 'default';
        }

        // '' = inherit the tenant default → normalise to null so `nullable`
        // short-circuits the `exists` rule.
        if ($this->input('project_key') === '') {
            $merge['project_key'] = null;
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->current();

        return [
            'label' => ['required', 'string', 'max:64', 'regex:/^[\pL\pN][\pL\pN _.-]*$/u'],
            'project_key' => [
                'sometimes', 'nullable', 'string', 'max:120',
                Rule::exists('projects', 'project_key')->where('tenant_id', $tenantId),
            ],
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Padosoft\AskMyDocsConnectorBase\Contracts\SupportsCredentialForm;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;

/**
 * v8.29 — validates a RE-configuration of an existing account's connection
 * parameters (`POST /api/admin/connectors/{installationId}/reconfigure` — the
 * Edit → Connection tab). The mirror of {@see ConfigureConnectorRequest} on an
 * existing row, with three deliberate differences:
 *
 *   1. The connector NAME is derived from the tenant-scoped installation row (the
 *      route only carries the numeric id), like {@see UpdateConnectorInstallationRequest}.
 *   2. The SECRET field(s) are ALWAYS optional (never `required_if`): a blank
 *      secret means "keep the current password", so the service verifies against
 *      the vaulted credential instead of demanding a re-type.
 *   3. `prepareForValidation` pre-fills every OMITTED non-secret field from the
 *      account's STORED value (falling back to the schema default) — so an
 *      unspecified connection field keeps its current value (PATCH semantics) and
 *      `required_if` is evaluated against the account's actual auth_mode.
 *
 * `label` / `project_key` are NOT reconfigured here (that is the metadata PATCH);
 * any such keys are simply ignored (no rule → excluded from validated()). The
 * secret is added to `$dontFlash` so it never lands in an error flash payload.
 */
final class ReconfigureConnectorRequest extends FormRequest
{
    /** @var list<string> */
    protected $dontFlash = ['password', 'password_confirmation'];

    private ?ConnectorInstallation $installation = null;

    private bool $installationResolved = false;

    public function authorize(): bool
    {
        return true;
    }

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

    private function connector(): ?SupportsCredentialForm
    {
        $installation = $this->installation();
        if ($installation === null) {
            return null;
        }

        $connector = app(\Padosoft\AskMyDocsConnectorBase\ConnectorRegistry::class)
            ->get($installation->connector_name);

        return $connector instanceof SupportsCredentialForm ? $connector : null;
    }

    /**
     * Before validation: (1) mask EVERY secret field in `$dontFlash`; (2) pre-fill
     * each OMITTED non-secret field from the account's STORED value (else the schema
     * default), so omitted connection fields keep their current value and
     * `required_if:auth_mode,…` is evaluated against the real stored auth_mode.
     * Secret fields are NEVER pre-filled (their value can't be read and blank means
     * "keep current").
     */
    protected function prepareForValidation(): void
    {
        $connector = $this->connector();
        $installation = $this->installation();
        if ($connector === null || $installation === null) {
            return;
        }

        $config = (array) ($installation->config_json ?? []);
        // Resolve the connection sub-map once behind an is_array guard: a
        // legacy/corrupted row whose `connection` is not an array would otherwise
        // TypeError on the `$connection[$name]` read below and 500 (PHP 8), instead
        // of cleanly falling back to schema defaults / stored top-level values.
        $connection = is_array($config['connection'] ?? null) ? $config['connection'] : [];
        $defaults = [];
        $secretFields = [];

        foreach ($connector->credentialFormSchema() as $field) {
            $name = (string) ($field['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $isSecret = ($field['target'] ?? null) === 'secret' || ($field['secret'] ?? false) === true;
            if ($isSecret) {
                $secretFields[] = $name;

                continue; // never pre-fill a secret
            }

            // Treat an explicit null the same as omitted (a JSON client may send
            // `auth_mode: null`). Pre-fill from the stored value first (PATCH:
            // keep current), then the schema default.
            if ($this->input($name) === null) {
                $stored = ((string) ($field['target'] ?? '')) === 'connection'
                    ? ($connection[$name] ?? null)
                    : ($config[$name] ?? null);
                $value = $stored ?? ($field['default'] ?? null);
                if ($value !== null) {
                    $defaults[$name] = $value;
                }
            }
        }

        $this->dontFlash = array_values(array_unique([...$this->dontFlash, ...$secretFields]));

        if ($defaults !== []) {
            $this->merge($defaults);
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $connector = $this->connector();
        if ($connector === null) {
            // Unknown / non-credential connector — the controller's findOr404 /
            // instanceof check returns 404. Nothing to validate.
            return [];
        }

        $rules = [];
        foreach ($connector->credentialFormSchema() as $field) {
            $name = (string) ($field['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $rules[$name] = $this->rulesForField($field);
        }

        return $rules;
    }

    /**
     * Rule set for one credential field. Identical to {@see ConfigureConnectorRequest}
     * EXCEPT secret fields are always optional here (blank = keep current) — never
     * `required`/`required_if`.
     *
     * @param  array<string,mixed>  $field
     * @return list<mixed>
     */
    private function rulesForField(array $field): array
    {
        $isSecret = ($field['target'] ?? null) === 'secret' || ($field['secret'] ?? false) === true;

        $rules = [];

        if ($isSecret) {
            // Always optional on reconfigure — a blank/omitted secret keeps the
            // vaulted credential; a present value re-authenticates.
            $rules[] = 'sometimes';
            $rules[] = 'nullable';
            $rules[] = 'string';

            return $rules;
        }

        $showIf = $field['showIf'] ?? null;
        $hasShowIf = is_array($showIf) && isset($showIf['field'], $showIf['equals']);

        if (($field['required'] ?? false) === true) {
            $rules[] = $hasShowIf
                ? 'required_if:'.$showIf['field'].','.$showIf['equals']
                : 'required';
        } else {
            $rules[] = 'sometimes';
            $rules[] = 'nullable';
        }

        match ((string) ($field['type'] ?? 'text')) {
            'number' => array_push($rules, 'integer', 'min:1'),
            'checkbox' => $rules[] = 'boolean',
            'select' => $rules[] = Rule::in(array_keys((array) ($field['options'] ?? []))),
            default => $rules[] = 'string',
        };

        return $rules;
    }
}

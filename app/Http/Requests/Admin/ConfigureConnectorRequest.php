<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Padosoft\AskMyDocsConnectorBase\ConnectorRegistry;
use Padosoft\AskMyDocsConnectorBase\Contracts\SupportsCredentialForm;

/**
 * v8.17 — validates a credential-connector configuration payload DYNAMICALLY
 * against the schema the connector advertises via {@see SupportsCredentialForm::credentialFormSchema()}.
 *
 * There is no IMAP-specific rule list here: rules are derived from each field's
 * type / required / showIf, so any future credential connector validates for
 * free. Authorization is enforced by the route middleware
 * (`auth:sanctum` + `tenant.authorize` + `can:manageConnectors`).
 *
 * The `password` (secret) is added to `$dontFlash` so it never lands in a
 * validation-error flash payload.
 */
final class ConfigureConnectorRequest extends FormRequest
{
    /** @var list<string> */
    protected $dontFlash = ['password', 'password_confirmation'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Before validation: (1) merge each schema field's default for any field the
     * client omitted, so `showIf`-derived `required_if` rules see their controlling
     * field present (e.g. an omitted `auth_mode` would otherwise default to `basic`
     * in the service while `required_if:auth_mode,basic` saw it MISSING and skipped
     * requiring host/password); (2) mask EVERY `target=secret` field in `$dontFlash`,
     * not just the literal `password`, so a future credential connector's secret is
     * never flashed either.
     */
    protected function prepareForValidation(): void
    {
        $connector = app(ConnectorRegistry::class)->get((string) $this->route('name'));

        if (! $connector instanceof SupportsCredentialForm) {
            return;
        }

        $defaults = [];
        $secretFields = [];

        foreach ($connector->credentialFormSchema() as $field) {
            $name = (string) ($field['name'] ?? '');
            if ($name === '') {
                continue;
            }

            // A secret is identified by target='secret' (the same marker
            // ConfigureConnectorService::splitPayload routes to the vault) — keyed
            // on target, not the secret flag, so the masking can never drift from
            // the actual routing. The secret flag is honoured too, defensively.
            if (($field['target'] ?? null) === 'secret' || ($field['secret'] ?? false) === true) {
                $secretFields[] = $name;
            }

            // Treat an explicit null the same as omitted (a JSON client may send
            // `auth_mode: null`): `input()` returns null for both, so the default
            // is merged in either case and required_if stays aligned with the
            // service's own default fallback.
            if ($this->input($name) === null && ($field['default'] ?? null) !== null) {
                $defaults[$name] = $field['default'];
            }
        }

        $this->dontFlash = array_values(array_unique([...$this->dontFlash, ...$secretFields]));

        // v8.20 multi-account: default an omitted/blank `label` to 'default'
        // (matching the column default) so the per-(tenant, connector) unique
        // rule below always evaluates — two label-less creates collide on
        // 'default' with a friendly 422 instead of a raw DB integrity error.
        if ($this->input('label') === null || $this->input('label') === '') {
            $defaults['label'] = 'default';
        }

        // A blank project binding means "inherit the tenant default" — normalise
        // '' → null so `nullable` short-circuits the `exists` rule (an empty
        // string is never a real project_key).
        if ($this->input('project_key') === '') {
            $defaults['project_key'] = null;
        }

        if ($defaults !== []) {
            $this->merge($defaults);
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $connector = app(ConnectorRegistry::class)->get((string) $this->route('name'));

        if (! $connector instanceof SupportsCredentialForm) {
            // Unknown / non-credential connector — the controller/service returns
            // 404. Nothing to validate.
            return [];
        }

        $tenantId = app(TenantContext::class)->current();
        $name = (string) $this->route('name');

        $rules = [
            // v8.20 — account discriminator. Required (a 'default' is merged in
            // prepareForValidation when omitted) and unique per (tenant, connector):
            // creating a second account with an existing label is a 422, never a
            // silent overwrite. The DB unique (tenant_id, connector_name, label) is
            // the authoritative backstop for the create-race.
            'label' => [
                'required', 'string', 'max:64',
                'regex:/^[\pL\pN][\pL\pN _.-]*$/u',
                Rule::unique('connector_installations', 'label')
                    ->where('tenant_id', $tenantId)
                    ->where('connector_name', $name),
            ],
            // v8.20 — optional KB project binding. Validated against the tenant's
            // REAL project registry (R18 — same domain the FE dropdown lists);
            // null/blank = the tenant default (kb.ingest.default_project).
            'project_key' => [
                'sometimes', 'nullable', 'string', 'max:120',
                Rule::exists('projects', 'project_key')->where('tenant_id', $tenantId),
            ],
        ];

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
     * @param  array<string,mixed>  $field
     * @return list<mixed>
     */
    private function rulesForField(array $field): array
    {
        $rules = [];

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

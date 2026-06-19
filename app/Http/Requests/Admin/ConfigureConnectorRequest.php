<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

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

        $rules = [
            'project_key' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];

        foreach ($connector->credentialFormSchema() as $field) {
            $rules[(string) $field['name']] = $this->rulesForField($field);
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

        if (($field['required'] ?? false) === true) {
            $rules[] = is_array($showIf)
                ? 'required_if:'.$showIf['field'].','.$showIf['equals']
                : 'required';
        } else {
            $rules[] = 'sometimes';
            $rules[] = 'nullable';
        }

        match ((string) $field['type']) {
            'number' => array_push($rules, 'integer', 'min:1'),
            'checkbox' => $rules[] = 'boolean',
            'select' => $rules[] = Rule::in(array_keys((array) ($field['options'] ?? []))),
            default => $rules[] = 'string',
        };

        return $rules;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Admin\Connectors;

use Padosoft\AskMyDocsConnectorBase\ConnectorRegistry;
use Padosoft\AskMyDocsConnectorBase\Contracts\SupportsCredentialForm;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * v8.29 — the SINGLE core (R44) behind the connector "Import parameters" surface.
 * Parses a previously-exported ({@see ConnectorConfigExportService}) config file
 * into a SAFE prefill for a NEW account: it validates the envelope, drops anything
 * that isn't a known non-secret field of the target connector, and reports which
 * secret fields the operator must (re)enter. It WRITES NOTHING — the actual create
 * goes through the normal {@see ConfigureConnectorService::configure} flow, where
 * the operator supplies the secret and the connection is verified (ping) before
 * persist.
 *
 * Surfaces:
 *   - HTTP : {@see \App\Http\Controllers\Api\Admin\ConnectorAdminController::importValidate}
 *            (returns the prefill; the FE opens the create form seeded with it).
 *   - PHP  : {@see \App\Console\Commands\ConnectorImportCommand} (parse + prompt
 *            secret + configure — the CLI equivalent of "prefill then Connect").
 *   - MCP  : intentionally NOT exposed (documented R44) — importing is a write that
 *            requires an interactive secret; the connector MCP surface is read-only.
 *
 * Security — a secret can NEVER round-trip through an import: even if a hostile /
 * hand-edited file carries a `password` (or any secret-flagged field), the
 * schema-driven filter drops it here, so it never reaches the create form or the
 * vault. Unknown keys are dropped too (an import can't inject arbitrary config).
 */
final class ConnectorConfigImportService
{
    public function __construct(
        private readonly ConnectorRegistry $registry,
    ) {}

    /**
     * Validate + sanitize an uploaded config blob into a prefill for connector
     * `$name`.
     *
     * @param  array<string,mixed>  $blob  The parsed export file (client-read JSON).
     * @return array{
     *     connector: string,
     *     label: ?string,
     *     project_key: ?string,
     *     params: array<string,mixed>,
     *     secret_fields_required: list<string>,
     *     dropped_keys: list<string>
     * }
     *
     * @throws NotFoundHttpException     unknown / non-credential connector
     * @throws ConnectorImportException  bad envelope / connector mismatch
     */
    public function parse(string $name, array $blob): array
    {
        $connector = $this->registry->get($name);
        if ($connector === null) {
            throw new NotFoundHttpException("Connector '{$name}' is not registered.");
        }
        if (! $connector instanceof SupportsCredentialForm) {
            throw new NotFoundHttpException("Connector '{$name}' does not support credential configuration.");
        }

        $this->assertRecognizedEnvelope($blob);
        $this->assertConnectorMatches($name, $blob);

        $incoming = $this->arrayValue($blob['params'] ?? null);

        [$params, $dropped] = $this->keepKnownNonSecret($connector->credentialFormSchema(), $incoming);

        return [
            'connector' => $name,
            // Prefill HINTS only — the create form may override; a label collision
            // or a missing project in the target tenant is resolved at Connect time.
            'label' => $this->stringOrNull($blob['label'] ?? null),
            'project_key' => $this->stringOrNull($blob['project_key'] ?? null),
            'params' => $params,
            'secret_fields_required' => $this->secretFieldsFor($connector->credentialFormSchema(), $params),
            'dropped_keys' => $dropped,
        ];
    }

    private function assertRecognizedEnvelope(array $blob): void
    {
        $format = $this->stringOrNull($this->metaValue($blob, 'format'));
        if ($format !== ConnectorConfigExportService::FORMAT) {
            throw new ConnectorImportException(
                'Unrecognized file — expected a connector-config export ('.ConnectorConfigExportService::FORMAT.').',
            );
        }
    }

    private function assertConnectorMatches(string $name, array $blob): void
    {
        // The file records which connector it came from (top-level, mirrored in
        // _meta). Importing an IMAP config into a different connector would prefill
        // fields that connector's schema doesn't have — reject rather than silently
        // drop everything.
        $fileConnector = $this->stringOrNull($blob['connector'] ?? $this->metaValue($blob, 'connector'));
        if ($fileConnector !== null && $fileConnector !== $name) {
            throw new ConnectorImportException(
                "This file is a '{$fileConnector}' config; it cannot be imported into '{$name}'.",
            );
        }
    }

    /**
     * Keep ONLY the incoming values that map to a known NON-secret credential-form
     * field; drop secrets (never round-trip a credential) and unknown keys (an
     * import can't inject arbitrary config). Returns the kept params + the sorted
     * list of dropped key names (so the UI can warn what was ignored).
     *
     * @param  list<array<string,mixed>>  $schema
     * @param  array<string,mixed>  $incoming
     * @return array{0: array<string,mixed>, 1: list<string>}
     */
    private function keepKnownNonSecret(array $schema, array $incoming): array
    {
        $allowed = [];
        foreach ($schema as $field) {
            $fieldName = (string) ($field['name'] ?? '');
            if ($fieldName === '') {
                continue;
            }
            $isSecret = ($field['target'] ?? null) === 'secret' || ($field['secret'] ?? false) === true;
            if (! $isSecret) {
                $allowed[$fieldName] = true;
            }
        }

        $params = [];
        $dropped = [];
        foreach ($incoming as $key => $value) {
            $key = (string) $key;
            if (isset($allowed[$key])) {
                $params[$key] = $value;

                continue;
            }
            $dropped[] = $key;
        }
        sort($dropped);

        return [$params, $dropped];
    }

    /**
     * The secret field names the operator must supply for the effective auth mode
     * of the prefill (a field hidden by an unmet `showIf` for that mode is not
     * required). The effective auth_mode is the prefilled value, else the schema
     * default — so the FE knows to require e.g. `password` for basic auth.
     *
     * @param  list<array<string,mixed>>  $schema
     * @param  array<string,mixed>  $params
     * @return list<string>
     */
    private function secretFieldsFor(array $schema, array $params): array
    {
        $authMode = $this->stringOrNull($params['auth_mode'] ?? null)
            ?? $this->schemaDefaultAuthMode($schema);

        $required = [];
        foreach ($schema as $field) {
            $isSecret = ($field['target'] ?? null) === 'secret' || ($field['secret'] ?? false) === true;
            if (! $isSecret) {
                continue;
            }

            $showIf = $field['showIf'] ?? null;
            if (is_array($showIf) && isset($showIf['field'], $showIf['equals'])) {
                // Only require the secret when its controlling field matches the
                // effective auth mode (e.g. `password` for basic, `ms_client_secret`
                // for client-credentials).
                if ($showIf['field'] === 'auth_mode' && $showIf['equals'] !== $authMode) {
                    continue;
                }
            }

            $required[] = (string) ($field['name'] ?? '');
        }

        return array_values(array_filter($required, static fn (string $n): bool => $n !== ''));
    }

    /**
     * @param  list<array<string,mixed>>  $schema
     */
    private function schemaDefaultAuthMode(array $schema): ?string
    {
        foreach ($schema as $field) {
            if ((string) ($field['name'] ?? '') === 'auth_mode') {
                return $this->stringOrNull($field['default'] ?? null);
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>
     */
    private function arrayValue(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * Safely read `_meta.<key>` — ONLY when `_meta` is actually an array. A malformed
     * file (e.g. `_meta: "x"`) must degrade to a clean 422 rejection, never a
     * TypeError/500: `?? null` does NOT catch subscripting a non-array (`"x"['format']`
     * throws in PHP 8). R14 — surface the failure as the intended import rejection.
     */
    private function metaValue(array $blob, string $key): mixed
    {
        $meta = $blob['_meta'] ?? null;

        return is_array($meta) ? ($meta[$key] ?? null) : null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_scalar($value) ? (string) $value : null;
    }
}

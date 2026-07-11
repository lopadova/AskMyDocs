<?php

declare(strict_types=1);

namespace App\Services\Admin\Connectors;

use App\Support\TenantContext;
use Padosoft\AskMyDocsConnectorBase\ConnectorRegistry;
use Padosoft\AskMyDocsConnectorBase\Contracts\SupportsCredentialForm;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * v8.29 — the SINGLE core (R44) behind the connector "Export parameters" surface.
 * Builds a portable, SECRET-FREE snapshot of an installation's connection
 * parameters + sync settings so an operator can copy an account's configuration
 * to another tenant / environment (the secret is re-entered on import — it never
 * travels in the file).
 *
 * Every surface delegates here so they never drift:
 *   - PHP  : {@see \App\Console\Commands\ConnectorExportCommand}.
 *   - HTTP : {@see \App\Http\Controllers\Api\Admin\ConnectorAdminController::export}
 *            (also the prefill source for the Edit → Connection tab, since the
 *            listing/resource shape deliberately hides connection values).
 *   - MCP  : intentionally NOT exposed (documented R44 deviation) — the connector
 *            MCP tools ({@see \App\Mcp\Tools\ConnectorSettingsTool}) deliberately
 *            never reveal connection params (host/username) to the LLM surface;
 *            an export tool would contradict that stance.
 *
 * Security (defence in depth):
 *   1. Reads ONLY `config_json` — the vault is NEVER touched, so an encrypted
 *      password/token can't leak even by mistake.
 *   2. Drops every schema field flagged secret (`target='secret'` OR `secret=true`
 *      — the same pair {@see ConfigureConnectorService::splitPayload} routes to the
 *      vault), so a connector that (wrongly) inlined a secret in `config_json`
 *      still never exports it. The dropped field names are reported back in
 *      `secret_fields_omitted` so the importer knows what the operator must supply.
 *   3. Tenant-scoped (R30) — a cross-tenant / unknown id 404s via
 *      {@see ConnectorInstallationService::findOr404}.
 */
final class ConnectorConfigExportService
{
    /** The envelope discriminator an import validates against. */
    public const FORMAT = 'askmydocs.connector-config';

    /** Bump when the envelope shape changes in a backward-incompatible way. */
    public const VERSION = 1;

    public function __construct(
        private readonly ConnectorRegistry $registry,
        private readonly ConnectorInstallationService $installations,
        private readonly ConnectorSettingsService $settings,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * Portable, secret-free snapshot of the installation's configuration.
     *
     * @return array<string,mixed>
     *
     * @throws NotFoundHttpException  unknown / cross-tenant id, or a connector that
     *                                does not support credential configuration.
     */
    public function export(int $installationId): array
    {
        // R30 — tenant-scoped; a cross-tenant / unknown id 404s here.
        $installation = $this->installations->findOr404($installationId);

        $connector = $this->registry->get($installation->connector_name);
        if (! $connector instanceof SupportsCredentialForm) {
            // Only credential connectors have a connection-parameter surface to
            // export; an OAuth-only connector 404s rather than emitting an empty blob.
            throw new NotFoundHttpException(
                "Connector '{$installation->connector_name}' does not support parameter export.",
            );
        }

        [$params, $omitted] = $this->nonSecretParams(
            $connector->credentialFormSchema(),
            (array) ($installation->config_json ?? []),
        );

        return [
            '_meta' => [
                'format' => self::FORMAT,
                'version' => self::VERSION,
                'connector' => $installation->connector_name,
                'tenant' => $this->tenantContext->current(),
                'exported_at' => now()->toIso8601String(),
            ],
            'connector' => $installation->connector_name,
            // A prefill HINT for the import form — the label + project binding are
            // NOT enforced on import (the target tenant may lack the project, and a
            // label collision is resolved there); they seed the create form only.
            'label' => $installation->label,
            'project_key' => $installation->project_key,
            // FLAT field-name → value, keyed exactly like the credential form the
            // import prefills (host, port, username, auth_mode, …).
            'params' => $params,
            // The post-install sync settings (folders / window / filters), nested as
            // the connector reads them — all non-secret by construction (every
            // connectionSettingsSchema field targets config_json).
            'settings' => $this->settings->currentSettings($installation),
            // Which secret fields the operator must re-enter on import.
            'secret_fields_omitted' => $omitted,
        ];
    }

    /**
     * Flatten the credential-form schema against the stored config_json into a
     * secret-free, field-name-keyed param map (the shape the import form seeds
     * from), and collect the names of the secret fields that were dropped.
     *
     * A field's `target` tells us WHERE its value lives in config_json:
     *   - `connection` → `config_json['connection'][<name>]`
     *   - anything else (auth_mode / provider / config) → `config_json[<name>]`
     * Secret fields are never read — only their names are collected.
     *
     * @param  list<array<string,mixed>>  $schema
     * @param  array<string,mixed>  $config
     * @return array{0: array<string,mixed>, 1: list<string>}  [params, secret_fields_omitted]
     */
    private function nonSecretParams(array $schema, array $config): array
    {
        $params = [];
        $omitted = [];

        // Resolve the connection sub-map ONCE, guarding a non-array `connection` in a
        // legacy/corrupted config_json — subscripting it inline (`$config['connection'][$name]`)
        // would throw a TypeError in PHP 8 and 500 the export instead of returning the
        // fields that ARE present (R14).
        $connection = is_array($config['connection'] ?? null) ? $config['connection'] : [];

        foreach ($schema as $field) {
            $name = (string) ($field['name'] ?? '');
            if ($name === '') {
                continue;
            }

            // Drop secrets by EITHER marker (the same pair splitPayload vaults),
            // reporting the name so the importer knows what to ask for.
            if (($field['target'] ?? null) === 'secret' || ($field['secret'] ?? false) === true) {
                $omitted[] = $name;

                continue;
            }

            $value = ((string) ($field['target'] ?? '')) === 'connection'
                ? ($connection[$name] ?? null)
                : ($config[$name] ?? null);

            // Only export values that are actually present — an absent field stays
            // absent so the import form falls back to its own schema default.
            if ($value !== null) {
                $params[$name] = $value;
            }
        }

        return [$params, $omitted];
    }
}

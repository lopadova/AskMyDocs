<?php

declare(strict_types=1);

namespace App\Services\Admin\Connectors;

use Illuminate\Support\Arr;
use Padosoft\AskMyDocsConnectorBase\ConnectorRegistry;
use Padosoft\AskMyDocsConnectorBase\Contracts\SupportsConnectionSettings;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;

/**
 * v8.25 — the SINGLE core (R44) for a connector's editable post-install sync
 * settings. Every surface reads/writes through here so they never drift:
 *   - HTTP : {@see ConnectorInstallationService::installationArray} (read,
 *            embeds the schema + current values) and the PATCH write path
 *            ({@see ConnectorInstallationService::applyConfigJsonEdits}).
 *   - MCP  : {@see \App\Mcp\Tools\ConnectorSettingsTool} (read).
 *   - PHP  : {@see \App\Console\Commands\ConnectorConfigureCommand} (read + write).
 *
 * The connector itself is the source of truth for WHAT is configurable: a
 * connector that implements
 * {@see SupportsConnectionSettings} advertises a field schema; the host renders
 * it generically (R23 — no connector-name branch) and stores the values in
 * `config_json` at each field's dotted path, exactly what the connector reads
 * back at sync time. A connector that does not implement the interface simply
 * has no editable settings (the schema is empty).
 *
 * Settings travel as a NESTED partial of `config_json` (so `folders.include`
 * lives at `['folders']['include']`), which round-trips 1:1 with both Laravel
 * validation and the value the connector reads.
 */
final class ConnectorSettingsService
{
    public function __construct(private readonly ConnectorRegistry $registry) {}

    /**
     * The connector's editable settings schema (a list of CredentialField shapes),
     * or [] when the connector advertises none.
     *
     * @return list<array<string,mixed>>
     */
    public function schemaFor(ConnectorInstallation $installation): array
    {
        $connector = $this->registry->get($installation->connector_name);

        return $connector instanceof SupportsConnectionSettings
            ? $connector->connectionSettingsSchema()
            : [];
    }

    /**
     * Whether the connector exposes any editable settings.
     */
    public function supports(ConnectorInstallation $installation): bool
    {
        return $this->registry->get($installation->connector_name) instanceof SupportsConnectionSettings;
    }

    /**
     * The current value of every schema field, as a NESTED partial of config_json
     * (the field's stored value, falling back to its schema default). The shape
     * the edit form seeds from and the PATCH payload mirrors.
     *
     * @param  list<array<string,mixed>>|null  $schema  a precomputed schema to reuse
     *                                                   (callers that already resolved it — the
     *                                                   resource, installationArray — pass it so the
     *                                                   connector/registry isn't queried twice)
     * @return array<string,mixed>
     */
    public function currentSettings(ConnectorInstallation $installation, ?array $schema = null): array
    {
        $config = (array) ($installation->config_json ?? []);
        $out = [];

        foreach ($schema ?? $this->schemaFor($installation) as $field) {
            $name = (string) ($field['name'] ?? '');
            if ($name === '') {
                continue;
            }
            data_set($out, $name, data_get($config, $name, $field['default'] ?? null));
        }

        return $out;
    }

    /**
     * Merge a validated settings payload (a nested partial of config_json) into the
     * installation's config_json. Only schema-declared fields that are PRESENT in
     * the payload are written, each overwritten WHOLE via data_set so a list value
     * is replaced (not element-merged) and config the operator never sees
     * (connection/auth_mode) is preserved untouched.
     *
     * @param  array<string,mixed>  $settings  nested partial of config_json
     * @return array<string,mixed>  the next config_json
     */
    public function mergeIntoConfig(ConnectorInstallation $installation, array $settings): array
    {
        $config = (array) ($installation->config_json ?? []);

        foreach ($this->schemaFor($installation) as $field) {
            $name = (string) ($field['name'] ?? '');
            if ($name === '') {
                continue;
            }
            if (Arr::has($settings, $name)) {
                data_set($config, $name, data_get($settings, $name));
            }
        }

        return $config;
    }
}

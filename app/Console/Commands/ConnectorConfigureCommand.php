<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Admin\Connectors\ConnectorInstallationService;
use App\Services\Admin\Connectors\ConnectorSettingsService;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * v8.25 — PHP write surface (R44) for editing a connector account's post-install
 * sync settings from the CLI. The third surface over the SAME core as the HTTP
 * PATCH (the {@see ConnectorInstallationService::updateMetadata} write path) and
 * the {@see \App\Mcp\Tools\ConnectorSettingsTool} read tool.
 *
 * `--show` prints the connector-advertised schema + current values. Each `--set`
 * is a `name=value` pair using the field's dotted name (e.g. `date_window_days=90`,
 * `folders.exclude=Trash,Spam`); the value is cast by the field's schema type
 * (number → int, checkbox → bool, multiselect/tags → comma-split list). Unknown
 * field names are rejected (no silent typo'd config). Tenant-scoped (R30).
 */
final class ConnectorConfigureCommand extends Command
{
    protected $signature = 'connectors:configure
                            {installation : The connector_installations id to configure}
                            {--tenant=default : Tenant the installation belongs to}
                            {--set=* : Setting override as name=value (repeatable; dotted names, list values comma-separated)}
                            {--show : Print the settings schema + current values and exit}';

    protected $description = 'Show or edit a connector account\'s post-install sync settings.';

    public function handle(
        ConnectorInstallationService $installations,
        ConnectorSettingsService $settings,
        TenantContext $tenants,
    ): int {
        $id = (int) $this->argument('installation');
        $tenant = (string) $this->option('tenant');
        $previous = $tenants->current();
        $tenants->set($tenant);

        try {
            return $this->process($installations, $settings, $id);
        } catch (NotFoundHttpException) {
            $this->error("Installation {$id} not found for tenant '{$tenant}'.");

            return self::FAILURE;
        } catch (\InvalidArgumentException $e) {
            // A `--set` value that can't be parsed for its schema type — fail with
            // a clear message + non-zero exit, never a silent coercion or a stack trace.
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            $tenants->set($previous);
        }
    }

    private function process(
        ConnectorInstallationService $installations,
        ConnectorSettingsService $settings,
        int $id,
    ): int {
        $installation = $installations->findOr404($id);
        $schema = $settings->schemaFor($installation);

        if ($schema === []) {
            $this->warn("Connector '{$installation->connector_name}' exposes no editable settings.");

            return self::SUCCESS;
        }

        /** @var array<string,array<string,mixed>> $byName */
        $byName = [];
        foreach ($schema as $field) {
            $byName[(string) $field['name']] = $field;
        }

        $sets = (array) $this->option('set');

        if ($this->option('show') || $sets === []) {
            return $this->showSettings($installation, $schema, $settings);
        }

        $payload = [];
        foreach ($sets as $pair) {
            [$name, $value] = array_pad(explode('=', (string) $pair, 2), 2, '');
            $name = trim($name);

            if (! isset($byName[$name])) {
                $this->error("Unknown setting '{$name}' for connector '{$installation->connector_name}'.");

                return self::FAILURE;
            }

            data_set($payload, $name, $this->cast($byName[$name], $value));
        }

        $installations->updateMetadata($id, ['settings' => $payload]);
        $this->info("Updated settings on installation {$id}.");

        return $this->showSettings($installation->refresh(), $schema, $settings);
    }

    /**
     * Cast a raw `--set` value to the field's schema type, failing fast on input
     * that can't be parsed (so a typo'd `date_window_days=oops` is rejected, not
     * silently coerced to 0).
     *
     * @param  array<string,mixed>  $field
     *
     * @throws \InvalidArgumentException when $value can't be parsed for the type
     */
    private function cast(array $field, string $value): mixed
    {
        $name = (string) $field['name'];

        return match ((string) ($field['type'] ?? 'text')) {
            'number' => $this->castNullableInt($name, $value),
            'checkbox' => $this->castBool($name, $value),
            'select' => $this->castSelect($name, (array) ($field['options'] ?? []), $value),
            'multiselect', 'tags' => array_values(array_filter(
                array_map('trim', $value === '' ? [] : explode(',', $value)),
                static fn ($v) => $v !== '',
            )),
            default => $value,
        };
    }

    /**
     * Cast a number-field `--set` value, treating an empty value (or the literal
     * `null`) as a clear-to-default — consistent with the HTTP/UI behaviour where
     * an emptied number field sends null and the service unsets the override.
     *
     * @throws \InvalidArgumentException when a non-empty value can't be parsed
     */
    private function castNullableInt(string $name, string $value): ?int
    {
        $trimmed = trim($value);
        if ($trimmed === '' || strtolower($trimmed) === 'null') {
            return null;
        }

        return $this->castInt($name, $trimmed);
    }

    private function castInt(string $name, string $value): int
    {
        $parsed = filter_var($value, FILTER_VALIDATE_INT);
        if ($parsed === false) {
            throw new \InvalidArgumentException("'{$value}' is not a valid integer for setting '{$name}'.");
        }

        return $parsed;
    }

    private function castBool(string $name, string $value): bool
    {
        $parsed = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($parsed === null) {
            throw new \InvalidArgumentException("'{$value}' is not a valid boolean (true/false/1/0/yes/no/on/off) for setting '{$name}'.");
        }

        return $parsed;
    }

    /**
     * @param  array<string,string>  $options
     */
    private function castSelect(string $name, array $options, string $value): string
    {
        if (! array_key_exists($value, $options)) {
            throw new \InvalidArgumentException(
                "'{$value}' is not a valid option for setting '{$name}' (allowed: ".implode(', ', array_keys($options)).').',
            );
        }

        return $value;
    }

    /**
     * @param  list<array<string,mixed>>  $schema
     */
    private function showSettings(
        \Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation $installation,
        array $schema,
        ConnectorSettingsService $settings,
    ): int {
        // Reuse the already-resolved schema (avoids a second registry/connector call).
        $current = $settings->currentSettings($installation, $schema);

        $rows = [];
        foreach ($schema as $field) {
            $name = (string) $field['name'];
            $value = data_get($current, $name);
            $rows[] = [
                $name,
                (string) $field['type'],
                is_array($value) ? implode(', ', array_map('strval', $value)) : (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value),
            ];
        }

        $this->table(['setting', 'type', 'value'], $rows);

        return self::SUCCESS;
    }
}

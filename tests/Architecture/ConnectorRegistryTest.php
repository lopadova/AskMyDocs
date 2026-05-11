<?php

declare(strict_types=1);

namespace Tests\Architecture;

use App\Connectors\ConnectorInterface;
use App\Connectors\ConnectorRegistry;
use Tests\TestCase;

/**
 * R23 — every connector FQCN registered via composer auto-discovery
 * or `config/connectors.php::built_in` MUST implement
 * {@see ConnectorInterface}. The registry itself enforces this at
 * boot (throws `RegistryConfigurationException` on mismatch); this
 * architecture test exercises the boot path against the REAL
 * configuration shipped on every PR.
 *
 * If this test fails after adding a new connector, you forgot to
 * `implements ConnectorInterface` on the new class.
 */
final class ConnectorRegistryTest extends TestCase
{
    public function test_every_registered_connector_implements_connector_interface(): void
    {
        $registry = $this->app->make(ConnectorRegistry::class);

        foreach ($registry->all() as $connector) {
            $this->assertInstanceOf(
                ConnectorInterface::class,
                $connector,
                sprintf(
                    "Connector '%s' (FQCN %s) does not implement %s.",
                    $connector->key(),
                    $connector::class,
                    ConnectorInterface::class,
                ),
            );
        }
    }

    public function test_registry_contains_built_in_google_drive_connector(): void
    {
        $registry = $this->app->make(ConnectorRegistry::class);

        $this->assertTrue(
            $registry->has('google-drive'),
            'GoogleDriveConnector is configured as a built-in but is not registered. Check config/connectors.php::built_in.'
        );
    }

    public function test_every_connector_key_is_kebab_case(): void
    {
        $registry = $this->app->make(ConnectorRegistry::class);

        foreach ($registry->all() as $connector) {
            $key = $connector->key();
            $this->assertMatchesRegularExpression(
                '/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                $key,
                "Connector key '{$key}' is not kebab-case. Connector keys must match /^[a-z0-9]+(?:-[a-z0-9]+)*$/."
            );
        }
    }
}

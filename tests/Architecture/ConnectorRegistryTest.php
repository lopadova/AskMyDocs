<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Padosoft\AskMyDocsConnectorBase\ConnectorInterface;
use Padosoft\AskMyDocsConnectorBase\ConnectorRegistry;
use Padosoft\AskMyDocsConnectorConfluence\ConfluenceConnector;
use Padosoft\AskMyDocsConnectorEvernote\EvernoteConnector;
use Padosoft\AskMyDocsConnectorFabric\FabricConnector;
use Padosoft\AskMyDocsConnectorGoogleDrive\GoogleDriveConnector;
use Padosoft\AskMyDocsConnectorJira\JiraConnector;
use Padosoft\AskMyDocsConnectorNotion\NotionConnector;
use Padosoft\AskMyDocsConnectorOneDrive\OneDriveConnector;
use Tests\TestCase;

/**
 * v4.6 — R23 architecture test. Every connector FQCN that the
 * `padosoft/askmydocs-connector-*` packages publish via
 * `composer.json::extra.askmydocs.connectors` MUST implement
 * {@see ConnectorInterface}. The registry enforces this at boot
 * (throws `RegistryConfigurationException` on mismatch); this test
 * exercises the boot path against the REAL connector FQCNs shipped
 * on every PR.
 *
 * Why we bypass `composer.lock` auto-discovery here: under Orchestra
 * Testbench, `base_path('composer.lock')` resolves into the vendor
 * skeleton, not the host project — so the registry's lockfile
 * walker sees an empty file. We instead pass the FQCN list
 * explicitly through the `built_in` config slot, which exercises
 * the exact same registration path (R23 FQCN validation,
 * duplicate-key detection, instance caching). In production, the
 * lockfile-driven discovery runs and produces the same registry.
 */
final class ConnectorRegistryTest extends TestCase
{
    /**
     * The seven connector FQCNs shipped as standalone composer packages
     * in v4.6. Keep this in lock-step with `composer.json::require`
     * and every package's own `extra.askmydocs.connectors`. Adding a
     * new connector package? Append it here AND in the host README +
     * v4.6 sister-packages row.
     *
     * @var list<class-string<ConnectorInterface>>
     */
    private const SHIPPED_CONNECTORS = [
        GoogleDriveConnector::class,
        NotionConnector::class,
        EvernoteConnector::class,
        FabricConnector::class,
        OneDriveConnector::class,
        ConfluenceConnector::class,
        JiraConnector::class,
    ];

    public function test_every_shipped_connector_implements_connector_interface(): void
    {
        $registry = $this->buildRegistryWithShippedConnectors();

        // Assert at least one connector registered so a silent
        // empty-registry regression is caught.
        $this->assertNotEmpty(
            $registry->keys(),
            'No connector FQCNs resolved by the registry. Did the package wiring drop?',
        );

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

    public function test_registry_contains_every_v46_connector_key(): void
    {
        $registry = $this->buildRegistryWithShippedConnectors();

        foreach (
            [
                'google-drive',
                'notion',
                'evernote',
                'fabric',
                'onedrive',
                'confluence',
                'jira',
            ] as $expectedKey
        ) {
            $this->assertTrue(
                $registry->has($expectedKey),
                sprintf(
                    "Connector '%s' must be discoverable through the registry. "
                    .'Check `composer.json::require` + the corresponding package SP '
                    .'in `bootstrap/providers.php` + `tests/TestCase.php`.',
                    $expectedKey,
                ),
            );
        }
    }

    public function test_every_connector_key_is_kebab_case(): void
    {
        $registry = $this->buildRegistryWithShippedConnectors();

        foreach ($registry->all() as $connector) {
            $key = $connector->key();
            $this->assertMatchesRegularExpression(
                '/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                $key,
                "Connector key '{$key}' is not kebab-case. "
                .'Connector keys must match /^[a-z0-9]+(?:-[a-z0-9]+)*$/.',
            );
        }
    }

    private function buildRegistryWithShippedConnectors(): ConnectorRegistry
    {
        return new ConnectorRegistry(
            $this->app,
            ['built_in' => self::SHIPPED_CONNECTORS],
            composerPackages: [],
        );
    }
}

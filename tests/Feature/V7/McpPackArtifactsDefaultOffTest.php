<?php

declare(strict_types=1);

namespace Tests\Feature\V7;

use Tests\TestCase;

/**
 * R43 — padosoft/askmydocs-mcp-pack 2.0 mounts a signed-URL artifact download
 * route unless `mcp-pack.artifacts.enabled` is false, and ships it ON. The
 * host does not use MCP artifacts, so its `config/mcp-pack.php` turns the route
 * off. The test bootstrap does not load the host's mcp-pack config, so these
 * assertions read the host file directly rather than the resolved config.
 */
final class McpPackArtifactsDefaultOffTest extends TestCase
{
    public function test_the_host_ships_the_artifact_download_route_off(): void
    {
        $host = require __DIR__.'/../../../config/mcp-pack.php';

        $this->assertFalse(
            $host['artifacts']['enabled'],
            'The pack ships artifact download ON; the host must override it to OFF.',
        );
    }

    public function test_the_host_block_restates_every_key_the_package_reads(): void
    {
        $host = require __DIR__.'/../../../config/mcp-pack.php';
        $package = require __DIR__.'/../../../vendor/padosoft/askmydocs-mcp-pack/config/mcp-pack.php';

        $this->assertIsArray($package['artifacts'] ?? null, 'The package no longer has an artifacts block; drop the host override.');
        $this->assertSame(
            array_keys($package['artifacts']),
            array_keys($host['artifacts']),
            'Config merges one level deep: the host artifacts block replaces the package block, so enabling it must not lose a key.',
        );
    }
}

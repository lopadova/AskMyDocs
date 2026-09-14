<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use Tests\TestCase;

final class McpConnectorConfigTest extends TestCase
{
    public function test_host_config_retains_package_defaults_required_by_connector_services(): void
    {
        $this->assertSame(
            ['auto', 'streamable_http', 'legacy_sse', 'stdio_imported'],
            config('connector-mcp.allowed_transports'),
        );
        $this->assertSame(5, config('connector-mcp.http.connect_timeout_seconds'));
        $this->assertSame(20, config('connector-mcp.http.max_catalog_pages'));
        $this->assertSame(500, config('connector-mcp.ingest.max_resources_per_sync'));
        $this->assertTrue(config('connector-mcp.oauth.pkce'));
        $this->assertTrue(config('connector-mcp.tool_policy.confirmation_for_unknown_writes'));
        $this->assertSame(250, config('connector-mcp.tasks.minimum_poll_interval_ms'));
        $this->assertContains(
            'text/html;profile=mcp-app',
            config('connector-mcp.apps.accepted_mime_types'),
        );
        $this->assertSame(24_000, config('connector-mcp.llm_text_limit'));
    }
}

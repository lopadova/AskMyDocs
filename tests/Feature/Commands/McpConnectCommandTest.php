<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class McpConnectCommandTest extends TestCase
{
    public function test_prints_mcp_json_snippet(): void
    {
        $code = Artisan::call('askmydocs:mcp:connect', [
            '--server' => 'https://askmydocs.example.com/',
            '--tenant' => 'acme',
            '--token' => 'tok_123',
        ]);

        $this->assertSame(0, $code);
        $out = Artisan::output();
        $this->assertStringContainsString('"mcpServers"', $out);
        $this->assertStringContainsString('askmydocs.example.com/mcp/kb', $out);
        $this->assertStringContainsString('"Authorization": "Bearer tok_123"', $out);
        $this->assertStringContainsString('"X-Tenant-Id": "acme"', $out);
    }

    public function test_missing_required_options_fails(): void
    {
        $this->artisan('askmydocs:mcp:connect', [
            '--server' => 'https://askmydocs.example.com',
            '--tenant' => 'acme',
        ])
            ->expectsOutputToContain('Missing required options')
            ->assertExitCode(1);
    }
}

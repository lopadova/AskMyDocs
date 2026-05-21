<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

final class McpConnectCommand extends Command
{
    protected $signature = 'askmydocs:mcp:connect
        {--server= : Base AskMyDocs URL, e.g. https://askmydocs.example.com}
        {--tenant= : Tenant id (X-Tenant-Id header)}
        {--token= : MCP tenant token (Bearer)}
        {--name=askmydocs : mcpServers entry key}';

    protected $description = 'Generate a Claude Code .mcp.json snippet for the AskMyDocs MCP debugger server.';

    public function handle(): int
    {
        $server = trim((string) $this->option('server'));
        $tenant = trim((string) $this->option('tenant'));
        $token = trim((string) $this->option('token'));
        $name = trim((string) $this->option('name'));

        if ($server === '' || $tenant === '' || $token === '' || $name === '') {
            $this->error('Missing required options: --server, --tenant, --token, --name.');
            return self::FAILURE;
        }

        $server = rtrim($server, '/');
        $snippet = [
            'mcpServers' => [
                $name => [
                    'type' => 'http',
                    'url' => $server . '/mcp/kb',
                    'headers' => [
                        'Authorization' => 'Bearer ' . $token,
                        'X-Tenant-Id' => $tenant,
                    ],
                ],
            ],
        ];

        $json = json_encode($snippet, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (! is_string($json)) {
            $this->error('Could not encode .mcp.json snippet.');
            return self::FAILURE;
        }

        $this->line($json);
        $this->newLine();
        $this->line('Copy this snippet into your consumer workspace `.mcp.json`.');

        return self::SUCCESS;
    }
}


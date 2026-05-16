<?php

declare(strict_types=1);

namespace Tests\Feature\V7;

use App\Mcp\Adapters\McpServerAdapter;
use App\Models\McpServer;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * v7.0/W6.3 — wraps the host's `App\Models\McpServer` row to the
 * package's `McpServerContract` shape. The contract identity is
 * load-bearing for the package's transport factory + audit
 * correlation, so every method is exercised explicitly.
 */
final class McpServerAdapterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        Cache::flush();
        app(TenantContext::class)->set('default');
    }

    public function test_basic_identity_passes_through(): void
    {
        $server = $this->makeServer([
            'name' => 'Acme MCP',
            'transport' => McpServer::TRANSPORT_HTTP,
            'endpoint' => 'http://example.test/mcp',
            'enabled_tools_json' => ['kb.search', 'kb.read'],
            'status' => McpServer::STATUS_ACTIVE,
            'tenant_id' => 'acme',
        ]);
        $adapter = new McpServerAdapter($server);

        // id() must be string per the package contract even though
        // the host's PK is an int.
        $this->assertSame((string) $server->id, $adapter->id());
        $this->assertSame('Acme MCP', $adapter->name());
        $this->assertSame('http', $adapter->transport());
        $this->assertSame('acme', $adapter->tenantId());
        $this->assertSame(['kb.search', 'kb.read'], $adapter->allowedTools());
        $this->assertTrue($adapter->isEnabled());
    }

    public function test_wildcard_allowed_tools_flattens_to_empty_array(): void
    {
        // The host's `['*']` sentinel means "every tool the server
        // advertises". The package's contract uses an empty array for
        // the same meaning, so the adapter normalises.
        $adapter = new McpServerAdapter($this->makeServer([
            'enabled_tools_json' => ['*'],
        ]));

        $this->assertSame([], $adapter->allowedTools());
    }

    public function test_disabled_status_propagates(): void
    {
        $adapter = new McpServerAdapter($this->makeServer([
            'status' => McpServer::STATUS_DISABLED,
        ]));

        $this->assertFalse($adapter->isEnabled());
    }

    public function test_http_transport_config_includes_decrypted_headers(): void
    {
        $authCipher = Crypt::encryptString(json_encode([
            'headers' => [
                'X-Custom' => 'shh',
            ],
            'token' => 'sk-test-1234',
        ]));
        $adapter = new McpServerAdapter($this->makeServer([
            'transport' => McpServer::TRANSPORT_HTTP,
            'endpoint' => 'https://api.example/mcp',
            'auth_config_encrypted' => $authCipher,
        ]));

        $config = $adapter->transportConfig();
        $this->assertSame('https://api.example/mcp', $config['endpoint']);
        $this->assertSame('shh', $config['headers']['X-Custom']);
        // `token` auto-promotes to `Authorization: Bearer …` so a
        // common shorthand in the host's auth_config doesn't require
        // every operator to spell out the standard header.
        $this->assertSame('Bearer sk-test-1234', $config['headers']['Authorization']);
    }

    public function test_http_transport_keeps_explicit_authorization_header(): void
    {
        // If `headers.Authorization` is already set, the `token`
        // shorthand MUST NOT overwrite it — the explicit shape is
        // authoritative.
        $authCipher = Crypt::encryptString(json_encode([
            'headers' => ['Authorization' => 'Basic abcd=='],
            'token' => 'sk-ignored',
        ]));
        $adapter = new McpServerAdapter($this->makeServer([
            'transport' => McpServer::TRANSPORT_HTTP,
            'auth_config_encrypted' => $authCipher,
        ]));

        $this->assertSame('Basic abcd==', $adapter->transportConfig()['headers']['Authorization']);
    }

    public function test_stdio_transport_config_splits_command_line(): void
    {
        $authCipher = Crypt::encryptString(json_encode([
            'env' => ['UPSTREAM_TOKEN' => 'tok'],
            'cwd' => '/srv/mcp',
        ]));
        $adapter = new McpServerAdapter($this->makeServer([
            'transport' => McpServer::TRANSPORT_STDIO,
            'endpoint' => '/usr/bin/python3 -m my_mcp --debug',
            'auth_config_encrypted' => $authCipher,
        ]));

        $config = $adapter->transportConfig();
        $this->assertSame('/usr/bin/python3', $config['command']);
        $this->assertSame(['-m', 'my_mcp', '--debug'], $config['args']);
        $this->assertSame('/srv/mcp', $config['cwd']);
        $this->assertSame(['UPSTREAM_TOKEN' => 'tok'], $config['env']);
    }

    public function test_stdio_transport_config_preserves_quoted_arguments(): void
    {
        // Operators may store full shell-style command lines in
        // `endpoint`. The adapter must keep quoted arguments
        // together so `--prefix="hello world"` doesn't shred into
        // three tokens (which would invoke the wrong process or
        // pass nonsense args).
        $adapter = new McpServerAdapter($this->makeServer([
            'transport' => McpServer::TRANSPORT_STDIO,
            'endpoint' => '/usr/bin/python3 -m my_mcp --prefix="hello world" --debug',
        ]));

        $config = $adapter->transportConfig();
        $this->assertSame('/usr/bin/python3', $config['command']);
        $this->assertSame(
            ['-m', 'my_mcp', '--prefix=hello world', '--debug'],
            $config['args'],
            'quoted "hello world" must survive as a single token',
        );
    }

    public function test_corrupt_auth_config_degrades_to_empty_map(): void
    {
        // A row whose encrypted blob can't be decrypted (key rotated,
        // payload truncated) MUST NOT crash the orchestrator. The
        // adapter returns an empty header map so the upstream call
        // fails on auth instead, which the audit row will record
        // cleanly as a transport error.
        $adapter = new McpServerAdapter($this->makeServer([
            'transport' => McpServer::TRANSPORT_HTTP,
            'auth_config_encrypted' => 'not-a-valid-cipher',
        ]));

        $headers = $adapter->transportConfig()['headers'];
        $this->assertSame([], $headers);
    }

    /** @param  array<string,mixed>  $overrides */
    private function makeServer(array $overrides = []): McpServer
    {
        // `users` isn't tenant-scoped — `tenant_id` is not in
        // User::$fillable and the column doesn't exist, so passing
        // it is a silent no-op (or worse, a MassAssignmentException
        // under strict mode). Drop it from the fixture.
        $user = User::create([
            'name' => 'Adapter test',
            'email' => 'adapter-'.uniqid('', true).'@test.local',
            'password' => Hash::make('secret123'),
        ]);
        return McpServer::create(array_merge([
            'tenant_id' => 'default',
            'name' => 'Test',
            'transport' => McpServer::TRANSPORT_HTTP,
            'endpoint' => 'http://example.test',
            'auth_config_encrypted' => null,
            'enabled_tools_json' => [],
            'status' => McpServer::STATUS_ACTIVE,
            'created_by' => $user->id,
        ], $overrides));
    }
}

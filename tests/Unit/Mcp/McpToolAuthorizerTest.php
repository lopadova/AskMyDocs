<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp;

use App\Mcp\Client\McpToolAuthorizer;
use App\Models\McpServer;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * v5.0/W4 — unit tests for tool-level authorization semantics.
 */
final class McpToolAuthorizerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        app(TenantContext::class)->set('default');
    }

    public function test_admin_and_super_admin_are_allowed_for_enabled_tools(): void
    {
        $authorizer = new McpToolAuthorizer();
        $admin = $this->makeAdmin();
        $server = $this->createServer(['enabled_tools_json' => ['search_docs', 'graph']]);

        $this->assertTrue($authorizer->canInvoke($admin, $server, 'search_docs'));

        $super = $this->makeSuperAdmin();
        $this->assertTrue($authorizer->canInvoke($super, $server, 'search_docs'));
    }

    public function test_users_with_wrong_role_are_not_allowed_even_with_matching_tool_list(): void
    {
        $authorizer = new McpToolAuthorizer();
        $viewer = $this->makeViewer();
        $server = $this->createServer(['enabled_tools_json' => ['search_docs', 'graph']]);

        $this->assertFalse($authorizer->canInvoke($viewer, $server, 'search_docs'));
    }

    public function test_tool_not_in_enabled_list_is_denied(): void
    {
        $authorizer = new McpToolAuthorizer();
        $admin = $this->makeAdmin();
        $server = $this->createServer(['enabled_tools_json' => ['search_docs']]);

        $this->assertFalse($authorizer->canInvoke($admin, $server, 'graph'));
    }

    public function test_empty_tool_list_denies_any_tool(): void
    {
        $authorizer = new McpToolAuthorizer();
        $admin = $this->makeAdmin();
        $server = $this->createServer(['enabled_tools_json' => []]);

        $this->assertFalse($authorizer->canInvoke($admin, $server, 'search_docs'));
    }

    public function test_wildcard_tool_list_allows_any_tool(): void
    {
        $authorizer = new McpToolAuthorizer();
        $admin = $this->makeAdmin();
        $server = $this->createServer(['enabled_tools_json' => ['*']]);

        $this->assertTrue($authorizer->canInvoke($admin, $server, 'any-tool-you-like'));
    }

    private function makeAdmin(): User
    {
        $user = User::create([
            'name' => 'MCP Admin',
            'email' => 'authorizer-admin-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);
        $user->assignRole('admin');

        return $user;
    }

    private function makeSuperAdmin(): User
    {
        $user = User::create([
            'name' => 'MCP Super',
            'email' => 'authorizer-super-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);
        $user->assignRole('super-admin');

        return $user;
    }

    private function makeViewer(): User
    {
        $user = User::create([
            'name' => 'MCP Viewer',
            'email' => 'authorizer-viewer-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);
        $user->assignRole('viewer');

        return $user;
    }

    private function createServer(array $overrides = []): McpServer
    {
        return McpServer::create(array_merge([
            'tenant_id' => app(TenantContext::class)->current(),
            'name' => 'server-'.uniqid(),
            'transport' => McpServer::TRANSPORT_HTTP,
            'endpoint' => 'http://127.0.0.1:3535',
            'enabled_tools_json' => ['*'],
            'status' => McpServer::STATUS_ACTIVE,
            'created_by' => User::first()?->id ?? 1,
        ], $overrides));
    }
}


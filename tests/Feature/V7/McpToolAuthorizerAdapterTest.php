<?php

declare(strict_types=1);

namespace Tests\Feature\V7;

use App\Mcp\Adapters\McpToolAuthorizerAdapter;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Padosoft\AskMyDocsMcpPack\Contracts\McpToolContract;
use Tests\TestCase;

/**
 * v7.0/W6.3 — host authorizer adapter. The policy:
 *
 *  - System contexts (`$actor === null`) are DENIED by default.
 *  - Users without `admin` or `super-admin` are DENIED.
 *  - Write tools (`isReadOnly() === false`) require `super-admin`
 *    even within the admin family.
 *
 * Tenant boundary is enforced UPSTREAM by the registry adapter +
 * route middleware — `users` doesn't carry a per-user `tenant_id`
 * in this host, so the authorizer focuses strictly on role + tool
 * shape.
 */
final class McpToolAuthorizerAdapterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        Cache::flush();
        app(TenantContext::class)->set('default');
    }

    public function test_null_actor_is_denied(): void
    {
        $authorizer = new McpToolAuthorizerAdapter();
        $this->assertFalse($authorizer->authorize(null, 'default', $this->readOnlyTool()));
    }

    public function test_user_without_role_is_denied(): void
    {
        $authorizer = new McpToolAuthorizerAdapter();
        $viewer = $this->user('viewer');

        $this->assertFalse($authorizer->authorize($viewer, 'default', $this->readOnlyTool()));
    }

    public function test_admin_can_invoke_read_only_tools(): void
    {
        $authorizer = new McpToolAuthorizerAdapter();
        $admin = $this->user('admin');

        $this->assertTrue($authorizer->authorize($admin, 'default', $this->readOnlyTool()));
    }

    public function test_admin_cannot_invoke_write_tools(): void
    {
        // Write tools require strict super-admin.
        $authorizer = new McpToolAuthorizerAdapter();
        $admin = $this->user('admin');

        $this->assertFalse($authorizer->authorize($admin, 'default', $this->writeTool()));
    }

    public function test_super_admin_can_invoke_write_tools(): void
    {
        $authorizer = new McpToolAuthorizerAdapter();
        $super = $this->user('super-admin');

        $this->assertTrue($authorizer->authorize($super, 'default', $this->writeTool()));
    }

    public function test_tenant_id_argument_does_not_affect_role_decision(): void
    {
        // The adapter intentionally does NOT consult `$tenantId`
        // (the host's users table carries no per-user tenant; the
        // registry + middleware enforce the boundary). Passing any
        // tenant string MUST yield the same result for the same
        // (actor, tool) pair.
        $authorizer = new McpToolAuthorizerAdapter();
        $admin = $this->user('admin');

        $this->assertTrue($authorizer->authorize($admin, 'acme', $this->readOnlyTool()));
        $this->assertTrue($authorizer->authorize($admin, 'globex', $this->readOnlyTool()));
        $this->assertTrue($authorizer->authorize($admin, null, $this->readOnlyTool()));
    }

    public function test_actor_resolved_from_user_id_string(): void
    {
        // Queue workers / console commands may pass a string id
        // instead of the User instance. The adapter MUST resolve.
        $authorizer = new McpToolAuthorizerAdapter();
        $super = $this->user('super-admin');

        $this->assertTrue(
            $authorizer->authorize((string) $super->id, 'default', $this->readOnlyTool()),
        );
    }

    private function user(string $role): User
    {
        $user = User::create([
            'name' => 'Authorizer test '.$role,
            'email' => "auth-{$role}-".uniqid('', true).'@test.local',
            'password' => Hash::make('secret123'),
        ]);
        $user->assignRole($role);
        return $user;
    }

    private function readOnlyTool(): McpToolContract
    {
        return new class implements McpToolContract {
            public function name(): string { return 'kb.search'; }
            public function description(): string { return 'search'; }
            public function schema(): array { return ['type' => 'object']; }
            public function isIdempotent(): bool { return true; }
            public function isReadOnly(): bool { return true; }
            public function invoke(array $arguments): mixed { return []; }
        };
    }

    private function writeTool(): McpToolContract
    {
        return new class implements McpToolContract {
            public function name(): string { return 'kb.promote'; }
            public function description(): string { return 'promote'; }
            public function schema(): array { return ['type' => 'object']; }
            public function isIdempotent(): bool { return false; }
            public function isReadOnly(): bool { return false; }
            public function invoke(array $arguments): mixed { return []; }
        };
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Admin;

use App\Models\McpTenantToken;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class McpTenantTokenControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function defineRoutes($router): void
    {
        $router->middleware('api')->prefix('api')->group(__DIR__.'/../../../../routes/api.php');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        app(TenantContext::class)->set('default');
        $this->seed(RbacSeeder::class);
    }

    public function test_index_lists_only_current_tenant_tokens(): void
    {
        $super = $this->makeSuperAdmin();

        McpTenantToken::query()->create([
            'tenant_id' => 'default',
            'label' => 'Token A',
            'token_hash' => hash('sha256', 'a'),
            'token_last4' => 'aaaa',
            'scopes_json' => ['mcp:read'],
        ]);
        McpTenantToken::query()->create([
            'tenant_id' => 'tenant-x',
            'label' => 'Token X',
            'token_hash' => hash('sha256', 'x'),
            'token_last4' => 'xxxx',
            'scopes_json' => ['mcp:read'],
        ]);

        $response = $this->actingAs($super)->getJson('/api/admin/mcp/tokens');
        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.label', 'Token A');
    }

    public function test_store_mints_plain_token_once_and_persists_only_hash(): void
    {
        $super = $this->makeSuperAdmin();

        $response = $this->actingAs($super)->postJson('/api/admin/mcp/tokens', [
            'label' => 'CI Consumer',
            'scopes' => ['mcp:read', 'mcp:tools:propose'],
        ]);

        $response->assertStatus(201);
        $plain = (string) $response->json('plain_token');
        $this->assertNotSame('', $plain);
        $this->assertStringStartsWith('askmd_', $plain);

        $row = McpTenantToken::query()->sole();
        $this->assertSame(hash('sha256', $plain), $row->token_hash);
        $this->assertSame(substr($plain, -4), $row->token_last4);
        $this->assertSame('CI Consumer', $row->label);
        $this->assertNull($row->revoked_at);
    }

    public function test_revoke_marks_token_revoked_at(): void
    {
        $super = $this->makeSuperAdmin();
        $token = McpTenantToken::query()->create([
            'tenant_id' => 'default',
            'label' => 'Token A',
            'token_hash' => hash('sha256', 'a'),
            'token_last4' => 'aaaa',
            'scopes_json' => ['mcp:read'],
        ]);

        $this->actingAs($super)
            ->postJson("/api/admin/mcp/tokens/{$token->id}/revoke")
            ->assertOk()
            ->assertJsonPath('data.id', $token->id);

        $token->refresh();
        $this->assertNotNull($token->revoked_at);
    }

    public function test_non_super_admin_gets_403(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->getJson('/api/admin/mcp/tokens')->assertStatus(403);
        $this->actingAs($admin)->postJson('/api/admin/mcp/tokens', ['label' => 'x'])->assertStatus(403);
    }

    private function makeSuperAdmin(): User
    {
        $user = User::query()->create([
            'name' => 'Super',
            'email' => 'super-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);
        $user->assignRole('super-admin');

        return $user;
    }

    private function makeAdmin(): User
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);
        $user->assignRole('admin');

        return $user;
    }
}


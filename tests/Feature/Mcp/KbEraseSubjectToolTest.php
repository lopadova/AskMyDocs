<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Mcp\Tools\KbEraseSubjectTool;
use App\Models\AdminCommandAudit;
use App\Models\User;
use App\Services\Kb\Pii\SubjectErasureService;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Laravel\Mcp\Request;
use Padosoft\PiiRedactor\TokenStore\Eloquent\PiiTokenMap;
use Tests\TestCase;

/**
 * v8.23 (Ciclo 4) — the GDPR Art.17 right-to-erasure MCP tool (R44).
 *
 * Direct handle() calls prove the tool's OWN pii.erase gate + audit + tenant
 * scoping (the MCP authorizer write-tool→super-admin gate is a separate layer).
 */
final class KbEraseSubjectToolTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'mario.rossi@example.com';

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->reset();
        $this->seed(RbacSeeder::class);
        Cache::flush();
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->reset();
        parent::tearDown();
    }

    private function user(string $role): User
    {
        $u = User::create([
            'name' => $role,
            'email' => $role.'-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);
        $u->assignRole($role);

        return $u;
    }

    private function vault(string $tenant, string $original): void
    {
        PiiTokenMap::create([
            'tenant_id' => $tenant,
            'token' => '[tok:email:'.substr(md5($tenant.$original), 0, 12).']',
            'original' => $original,
            'detector' => 'email',
        ]);
    }

    private function invoke(array $values): \Laravel\Mcp\Response
    {
        return (new KbEraseSubjectTool())->handle(
            new Request(['values' => $values]),
            app(SubjectErasureService::class),
            app(TenantContext::class),
        );
    }

    public function test_super_admin_can_erase_and_it_is_audited(): void
    {
        $this->vault('default', self::EMAIL);
        $this->actingAs($this->user('super-admin'));

        $response = $this->invoke([self::EMAIL]);
        $payload = json_decode((string) $response->content(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $payload['erased']);
        $this->assertDatabaseMissing('pii_token_maps', ['tenant_id' => 'default', 'original' => self::EMAIL]);
        $this->assertDatabaseHas('admin_command_audit', [
            'command' => 'pii.erase',
            'status' => AdminCommandAudit::STATUS_COMPLETED,
        ]);
    }

    public function test_caller_without_permission_is_refused_and_audited(): void
    {
        $this->vault('default', self::EMAIL);
        $this->actingAs($this->user('admin')); // admin lacks pii.erase

        $response = $this->invoke([self::EMAIL]);

        $this->assertStringContainsString('Forbidden', (string) $response->content());
        $this->assertDatabaseHas('pii_token_maps', ['tenant_id' => 'default', 'original' => self::EMAIL]);
        $this->assertDatabaseHas('admin_command_audit', [
            'command' => 'pii.erase',
            'status' => AdminCommandAudit::STATUS_REJECTED,
        ]);
    }

    public function test_it_rejects_an_oversized_value(): void
    {
        $this->actingAs($this->user('super-admin'));

        $response = $this->invoke([str_repeat('a', 256)]);

        $this->assertStringContainsString('255 characters', (string) $response->content());
    }

    public function test_erasure_never_crosses_tenants(): void
    {
        $this->vault('default', self::EMAIL);
        $this->vault('globex', self::EMAIL);
        $this->actingAs($this->user('super-admin'));

        $this->invoke([self::EMAIL]);

        $this->assertDatabaseHas('pii_token_maps', ['tenant_id' => 'globex', 'original' => self::EMAIL]);
    }
}

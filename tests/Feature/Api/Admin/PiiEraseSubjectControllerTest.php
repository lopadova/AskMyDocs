<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Admin;

use App\Models\AdminCommandAudit;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Padosoft\PiiRedactor\TokenStore\Eloquent\PiiTokenMap;
use Tests\TestCase;

/**
 * v8.23 (Ciclo 4) — HTTP GDPR Art.17 right-to-erasure endpoint.
 *
 * Mirrors the detokenise contracts: 403 audited rejection (no pii.erase),
 * 200 audited success (dpo), 422 validation, tenant isolation (R30), guest 401.
 */
final class PiiEraseSubjectControllerTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'mario.rossi@example.com';

    protected function defineRoutes($router): void
    {
        $router->middleware('api')->prefix('api')->group(__DIR__.'/../../../../routes/api.php');
    }

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->reset();
        $this->seed(RbacSeeder::class);
        Cache::flush();
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

    public function test_admin_without_permission_gets_403_and_an_audited_rejection(): void
    {
        $this->vault('default', self::EMAIL);

        $this->actingAs($this->user('admin'))
            ->postJson('/api/admin/pii/erase-subject', ['values' => [self::EMAIL]])
            ->assertForbidden();

        // The vault row survives a denied attempt.
        $this->assertDatabaseHas('pii_token_maps', ['tenant_id' => 'default', 'original' => self::EMAIL]);
        $audit = AdminCommandAudit::query()->where('command', 'pii.erase')->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertSame(AdminCommandAudit::STATUS_REJECTED, $audit->status);
    }

    public function test_dpo_can_erase_and_it_is_audited(): void
    {
        $this->vault('default', self::EMAIL);

        $this->actingAs($this->user('dpo'))
            ->postJson('/api/admin/pii/erase-subject', ['values' => [self::EMAIL]])
            ->assertOk()
            ->assertJsonPath('erased', 1)
            ->assertJsonPath('value_count', 1);

        $this->assertDatabaseMissing('pii_token_maps', ['tenant_id' => 'default', 'original' => self::EMAIL]);
        $audit = AdminCommandAudit::query()
            ->where('command', 'pii.erase')->where('status', AdminCommandAudit::STATUS_COMPLETED)->latest('id')->first();
        $this->assertNotNull($audit);
        // The audit args record the COUNT, never the raw PII value.
        $this->assertSame(1, $audit->args_json['value_count']);
        $this->assertArrayNotHasKey('values', $audit->args_json);
    }

    public function test_missing_values_is_rejected_with_422(): void
    {
        $this->actingAs($this->user('dpo'))
            ->postJson('/api/admin/pii/erase-subject', [])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('values');
    }

    public function test_erasure_never_crosses_tenants(): void
    {
        // A 'globex' vault row with the same value must survive a default-tenant erase.
        $this->vault('default', self::EMAIL);
        $this->vault('globex', self::EMAIL);

        $this->actingAs($this->user('dpo'))
            ->postJson('/api/admin/pii/erase-subject', ['values' => [self::EMAIL]])
            ->assertOk()
            ->assertJsonPath('erased', 1);

        $this->assertDatabaseHas('pii_token_maps', ['tenant_id' => 'globex', 'original' => self::EMAIL]);
    }

    public function test_guest_is_rejected_with_401(): void
    {
        $this->postJson('/api/admin/pii/erase-subject', ['values' => [self::EMAIL]])->assertUnauthorized();
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Ai\AiManager;
use App\Ai\Providers\AnthropicProvider;
use App\Ai\Providers\OpenAiProvider;
use App\Models\AppSetting;
use App\Models\User;
use App\Services\Admin\AppSettingsResolver;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * v8.22 (Ciclo 3) — runtime configuration governance: the layered resolver
 * core, its AiManager wiring (per-tenant AI provider override), and the three
 * surfaces (HTTP super-admin endpoint + CLI commands; MCP registration is in
 * KnowledgeBaseServerRegistrationTest). R30 tenant-scoped, R43 both states,
 * R44 tri-surface.
 */
final class AppSettingsGovernanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        Cache::flush();
        config(['ai.default' => 'openai']);
    }

    // ----- Resolver core (layering) ----------------------------------------

    public function test_effective_falls_back_to_config_default_when_no_override(): void
    {
        // R43 OFF path — with no app_settings row the governable key resolves to
        // exactly the config default, i.e. pre-v8.22 behaviour.
        $resolver = new AppSettingsResolver;

        $this->assertSame('openai', $resolver->effective('ai.provider', 'default'));
    }

    public function test_tenant_wildcard_override_beats_config_default(): void
    {
        AppSetting::create([
            'tenant_id' => 'default',
            'project_key' => AppSetting::WILDCARD,
            'setting_key' => 'ai.provider',
            'value_json' => 'anthropic',
        ]);

        $resolver = new AppSettingsResolver;

        $this->assertSame('anthropic', $resolver->effective('ai.provider', 'default'));
    }

    public function test_exact_project_override_beats_tenant_wildcard(): void
    {
        // connector.sync_cadence_minutes is scope=both — genuinely project-layered.
        $resolver = new AppSettingsResolver;
        $resolver->set('connector.sync_cadence_minutes', 60, 'default');
        $resolver->set('connector.sync_cadence_minutes', 30, 'default', 'engineering');

        $this->assertSame(30, $resolver->effective('connector.sync_cadence_minutes', 'default', 'engineering'));
        // A different project still sees the tenant-wide override, not the
        // engineering one (R30 — overrides are per (tenant, project)).
        $this->assertSame(60, $resolver->effective('connector.sync_cadence_minutes', 'default', 'sales'));
    }

    public function test_tenant_scoped_key_ignores_a_stray_project_row_on_read(): void
    {
        // ai.provider is scope=tenant: even a manually-inserted/legacy project
        // row must NOT change the read (reads honour scope like writes, R16).
        AppSetting::create([
            'tenant_id' => 'default',
            'project_key' => AppSetting::WILDCARD,
            'setting_key' => 'ai.provider',
            'value_json' => 'anthropic',
        ]);
        AppSetting::create([
            'tenant_id' => 'default',
            'project_key' => 'engineering',
            'setting_key' => 'ai.provider',
            'value_json' => 'gemini',
        ]);

        $resolver = new AppSettingsResolver;

        $this->assertSame('anthropic', $resolver->effective('ai.provider', 'default', 'engineering'));
        $rows = collect($resolver->all('default', 'engineering'))->keyBy('key');
        $this->assertSame('anthropic', $rows['ai.provider']['value']);
        $this->assertSame('tenant', $rows['ai.provider']['source']);
    }

    public function test_overrides_are_isolated_per_tenant(): void
    {
        AppSetting::create([
            'tenant_id' => 'tenant-a',
            'project_key' => AppSetting::WILDCARD,
            'setting_key' => 'ai.provider',
            'value_json' => 'anthropic',
        ]);

        $resolver = new AppSettingsResolver;

        $this->assertSame('anthropic', $resolver->effective('ai.provider', 'tenant-a'));
        // tenant-b never set it → config default (no cross-tenant leak).
        $this->assertSame('openai', $resolver->effective('ai.provider', 'tenant-b'));
    }

    public function test_int_setting_is_cast_and_range_checked(): void
    {
        $resolver = new AppSettingsResolver;

        $resolver->set('connector.sync_cadence_minutes', '60', 'default');
        $this->assertSame(60, $resolver->effective('connector.sync_cadence_minutes', 'default'));

        $this->expectExceptionMessageMatches('/between 5 and 1440/');
        $resolver->set('connector.sync_cadence_minutes', 3, 'default');
    }

    public function test_enum_setting_rejects_unknown_value(): void
    {
        $resolver = new AppSettingsResolver;

        $this->expectExceptionMessageMatches('/must be one of/');
        $resolver->set('ai.provider', 'not-a-provider', 'default');
    }

    public function test_set_rejects_unknown_key(): void
    {
        $resolver = new AppSettingsResolver;

        $this->expectExceptionMessageMatches('/Unknown setting/');
        $resolver->set('totally.made.up', 'x', 'default');
    }

    public function test_set_rejects_deploy_only_key(): void
    {
        $resolver = new AppSettingsResolver;

        $this->expectExceptionMessageMatches('/deploy-managed/');
        $resolver->set('ai_finops.enabled', true, 'default');
    }

    public function test_normalize_project_key_handles_non_scalar_and_whitespace(): void
    {
        $this->assertSame('*', AppSetting::normalizeProjectKey(['x']));
        $this->assertSame('*', AppSetting::normalizeProjectKey(null));
        $this->assertSame('*', AppSetting::normalizeProjectKey('   '));
        // A real key — including the literal '0' — is preserved.
        $this->assertSame('0', AppSetting::normalizeProjectKey('0'));
        $this->assertSame('engineering', AppSetting::normalizeProjectKey('  engineering '));
    }

    public function test_read_skips_a_corrupt_override_row_and_falls_back(): void
    {
        // A manual/corrupt row that would NOT pass set()'s validation (below the
        // min) must be ignored on read, falling back to the config default —
        // never silently coerced to a bad value (R14).
        config(['connectors.default_sync_cadence_minutes' => 15]);
        AppSetting::create([
            'tenant_id' => 'default',
            'project_key' => AppSetting::WILDCARD,
            'setting_key' => 'connector.sync_cadence_minutes',
            'value_json' => 3, // below min 5 → invalid
        ]);
        AppSetting::create([
            'tenant_id' => 'default',
            'project_key' => AppSetting::WILDCARD,
            'setting_key' => 'ai.provider',
            'value_json' => 'bogus-provider', // not in enum → invalid
        ]);

        $resolver = new AppSettingsResolver;

        $this->assertSame(15, $resolver->effective('connector.sync_cadence_minutes', 'default'));
        $this->assertSame('openai', $resolver->effective('ai.provider', 'default'));

        $rows = collect($resolver->all('default'))->keyBy('key');
        $this->assertSame('config', $rows['connector.sync_cadence_minutes']['source']);
        $this->assertSame('config', $rows['ai.provider']['source']);
    }

    public function test_set_rejects_project_override_for_tenant_scoped_key(): void
    {
        $resolver = new AppSettingsResolver;

        // ai.provider is tenant-scoped — a per-project override is rejected
        // rather than silently accepted (would falsify provenance).
        $this->expectExceptionMessageMatches('/tenant-scoped/');
        $resolver->set('ai.provider', 'anthropic', 'default', 'engineering');
    }

    public function test_int_setting_rejects_decimal(): void
    {
        $resolver = new AppSettingsResolver;

        // A decimal must NOT be silently truncated to an int.
        $this->expectExceptionMessageMatches('/must be an integer/');
        $resolver->set('connector.sync_cadence_minutes', '12.5', 'default');
    }

    public function test_int_setting_rejects_float_and_scientific_strings(): void
    {
        $resolver = new AppSettingsResolver;

        foreach (['60.0', '60e0', '6e1', ' 60.0 '] as $bad) {
            try {
                $resolver->set('connector.sync_cadence_minutes', $bad, 'default');
                $this->fail("Expected '{$bad}' to be rejected as a non-integer.");
            } catch (\Illuminate\Validation\ValidationException $e) {
                $this->assertStringContainsString('must be an integer', $e->getMessage());
            }
        }

        // A pure-digit string (and surrounding whitespace) is still accepted.
        $resolver->set('connector.sync_cadence_minutes', ' 60 ', 'default');
        $this->assertSame(60, $resolver->effective('connector.sync_cadence_minutes', 'default'));
    }

    public function test_set_rejects_overlong_project_key(): void
    {
        $resolver = new AppSettingsResolver;

        $this->expectExceptionMessageMatches('/must not exceed 120/');
        $resolver->set('connector.sync_cadence_minutes', 60, 'default', str_repeat('a', 121));
    }

    public function test_set_null_clears_the_override(): void
    {
        $resolver = new AppSettingsResolver;

        $resolver->set('ai.provider', 'anthropic', 'default');
        $this->assertSame('anthropic', $resolver->effective('ai.provider', 'default'));

        $resolver->set('ai.provider', null, 'default');
        $this->assertDatabaseMissing('app_settings', [
            'tenant_id' => 'default',
            'setting_key' => 'ai.provider',
        ]);
        $this->assertSame('openai', $resolver->effective('ai.provider', 'default'));
    }

    public function test_all_reports_provenance_source(): void
    {
        $resolver = new AppSettingsResolver;
        $resolver->set('ai.provider', 'anthropic', 'default');

        $rows = collect($resolver->all('default'))->keyBy('key');

        $this->assertSame('tenant', $rows['ai.provider']['source']);
        $this->assertSame('config', $rows['connector.sync_cadence_minutes']['source']);
        $this->assertTrue($rows['ai_finops.enabled']['deploy_only']);
    }

    // ----- AiManager wiring ------------------------------------------------

    public function test_aimanager_uses_config_default_when_no_override(): void
    {
        // R43 OFF path — unchanged from pre-v8.22.
        app(TenantContext::class)->set('default');

        $this->assertInstanceOf(OpenAiProvider::class, (new AiManager)->provider());
    }

    public function test_aimanager_honours_per_tenant_provider_override(): void
    {
        app(AppSettingsResolver::class)->set('ai.provider', 'anthropic', 'default');
        app(TenantContext::class)->set('default');

        $this->assertInstanceOf(AnthropicProvider::class, (new AiManager)->provider());
    }

    public function test_aimanager_ignores_a_stale_unconfigured_provider_override(): void
    {
        // A stored override that is no longer a configured provider (removed
        // from config, or a bad manual DB value) must NOT reach resolve() and
        // throw — provider() falls back to the config default (R43/R14).
        app(AppSettingsResolver::class)->set('ai.provider', 'anthropic', 'default');
        config(['ai.providers.anthropic' => null]);
        app(TenantContext::class)->set('default');

        $this->assertInstanceOf(OpenAiProvider::class, (new AiManager)->provider());
    }

    public function test_aimanager_falls_back_to_config_default_when_resolver_throws(): void
    {
        // R43/R14 OFF-safe: a governance/DB failure must NEVER break the chat
        // path — provider() resolves the config default regardless.
        $throwing = new class extends AppSettingsResolver
        {
            public function effective(string $key, string $tenantId, string $projectKey = AppSetting::WILDCARD): mixed
            {
                throw new \RuntimeException('governance backend down');
            }
        };
        $this->app->instance(AppSettingsResolver::class, $throwing);
        app(TenantContext::class)->set('default');

        $this->assertInstanceOf(OpenAiProvider::class, (new AiManager)->provider());
    }

    // ----- HTTP surface ----------------------------------------------------

    public function test_super_admin_reads_settings(): void
    {
        $resp = $this->actingAs($this->superAdmin())->getJson('/api/admin/app-settings');

        $resp->assertOk()
            ->assertJsonPath('data.0.key', 'ai.provider')
            ->assertJsonPath('data.0.source', 'config');
    }

    public function test_super_admin_sets_an_override_via_http(): void
    {
        $this->actingAs($this->superAdmin())
            ->putJson('/api/admin/app-settings', ['key' => 'ai.provider', 'value' => 'anthropic'])
            ->assertOk();

        $this->assertDatabaseHas('app_settings', [
            'tenant_id' => 'default',
            'project_key' => '*',
            'setting_key' => 'ai.provider',
        ]);

        $this->actingAs($this->superAdmin())
            ->getJson('/api/admin/app-settings')
            ->assertJsonPath('data.0.value', 'anthropic')
            ->assertJsonPath('data.0.source', 'tenant');
    }

    public function test_http_normalises_empty_project_key_to_wildcard(): void
    {
        // A tenant '*' override must surface as source=tenant even when the
        // caller passes an empty ?project_key= (normalised to the wildcard,
        // not resolved as a literal empty project scope).
        app(AppSettingsResolver::class)->set('ai.provider', 'anthropic', 'default');

        $this->actingAs($this->superAdmin())
            ->getJson('/api/admin/app-settings?project_key=')
            ->assertOk()
            ->assertJsonPath('data.0.value', 'anthropic')
            ->assertJsonPath('data.0.source', 'tenant');
    }

    public function test_http_accepts_explicit_null_project_key(): void
    {
        // The UI sends project_key: null for "no project selected" — it must
        // normalise to the tenant-wide wildcard, not 422.
        $this->actingAs($this->superAdmin())
            ->putJson('/api/admin/app-settings', ['key' => 'ai.provider', 'value' => 'anthropic', 'project_key' => null])
            ->assertOk();

        $this->assertDatabaseHas('app_settings', [
            'tenant_id' => 'default',
            'project_key' => '*',
            'setting_key' => 'ai.provider',
        ]);
    }

    public function test_http_rejects_deploy_only_key_with_422(): void
    {
        $this->actingAs($this->superAdmin())
            ->putJson('/api/admin/app-settings', ['key' => 'ai_finops.enabled', 'value' => true])
            ->assertStatus(422);
    }

    public function test_http_requires_super_admin(): void
    {
        // Guest → 401; non-super-admin → 403 (R32).
        $this->getJson('/api/admin/app-settings')->assertStatus(401);
        $this->actingAs($this->regularAdmin())
            ->getJson('/api/admin/app-settings')->assertStatus(403);
    }

    // ----- CLI surface -----------------------------------------------------

    public function test_cli_set_then_list(): void
    {
        $this->artisan('app-settings:set', ['key' => 'ai.provider', 'value' => 'anthropic'])
            ->assertSuccessful();

        $this->assertDatabaseHas('app_settings', [
            'tenant_id' => 'default',
            'setting_key' => 'ai.provider',
        ]);

        $this->artisan('app-settings:list')
            ->expectsOutputToContain('ai.provider')
            ->assertSuccessful();
    }

    public function test_cli_clear_removes_override(): void
    {
        app(AppSettingsResolver::class)->set('ai.provider', 'anthropic', 'default');

        $this->artisan('app-settings:set', ['key' => 'ai.provider', '--clear' => true])
            ->assertSuccessful();

        $this->assertDatabaseMissing('app_settings', [
            'tenant_id' => 'default',
            'setting_key' => 'ai.provider',
        ]);
    }

    public function test_cli_set_rejects_deploy_only_key(): void
    {
        $this->artisan('app-settings:set', ['key' => 'ai_finops.enabled', 'value' => 'true'])
            ->assertFailed();
    }

    private function superAdmin(): User
    {
        $user = User::create(['name' => 'Super', 'email' => 'super-'.uniqid().'@demo.local', 'password' => Hash::make('secret123')]);
        $user->assignRole('super-admin');

        return $user;
    }

    private function regularAdmin(): User
    {
        $user = User::create(['name' => 'Admin', 'email' => 'admin-'.uniqid().'@demo.local', 'password' => Hash::make('secret123')]);
        $user->assignRole('admin');

        return $user;
    }
}

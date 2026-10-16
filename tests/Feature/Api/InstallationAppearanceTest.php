<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\InstallationAppearance as AppearanceRecord;
use App\Models\User;
use App\Services\Appearance\InstallationAppearance;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class InstallationAppearanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_bootstrap_defaults_to_humanistic_without_creating_a_setting(): void
    {
        $this->getJson('/api/appearance')
            ->assertOk()
            ->assertExactJson(['data' => ['theme' => 'humanistic']])
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->assertDatabaseCount('installation_appearance', 0);
    }

    public function test_management_requires_authentication(): void
    {
        $this->getJson('/api/system/appearance')->assertUnauthorized();
        $this->putJson('/api/system/appearance', ['theme' => 'luxury'])->assertUnauthorized();
        $this->assertDatabaseCount('installation_appearance', 0);
    }

    public static function nonSuperAdminRoles(): array
    {
        return array_map(fn (string $role): array => [$role], ['admin', 'editor', 'viewer', 'dpo', 'system-admin']);
    }

    #[DataProvider('nonSuperAdminRoles')]
    public function test_platform_permission_and_other_roles_do_not_grant_theme_management(string $role): void
    {
        $user = $this->user($role);
        Permission::findOrCreate('platform.admin', 'web');
        $user->givePermissionTo('platform.admin');
        $this->actingAsWithoutTenant($user);

        $this->getJson('/api/system/appearance')->assertForbidden();
        $this->putJson('/api/system/appearance', ['theme' => 'luxury'])->assertForbidden();
        $this->assertDatabaseCount('installation_appearance', 0);
    }

    public static function designThemes(): array
    {
        return array_map(fn (string $theme): array => [$theme], [
            'humanistic', 'tech-pro', 'retro-future', 'dynamic-office', 'luxury',
            'cartographic', 'polar', 'orbital', 'botanical', 'oceanic',
            'newsroom', 'archaeological', 'alpine', 'paper-world', 'blueprint',
            'semantic-orbits', 'prismatic-index', 'ink-flow',
        ]);
    }

    public function test_super_admin_without_a_tenant_can_persist_all_eighteen_themes_in_one_global_record(): void
    {
        $user = $this->user('super-admin');
        $this->actingAsWithoutTenant($user);
        $this->getJson('/api/system/appearance')->assertOk()->assertJsonPath('data.theme', 'humanistic');
        $themes = array_column(self::designThemes(), 0);
        $this->assertEqualsCanonicalizing($themes, InstallationAppearance::THEMES);

        foreach ($themes as $theme) {
            $this->putJson('/api/system/appearance', ['theme' => $theme])
                ->assertOk()->assertExactJson(['data' => ['theme' => $theme]]);
            $this->getJson('/api/system/appearance')->assertOk()->assertJsonPath('data.theme', $theme);
            $this->getJson('/api/appearance')->assertOk()->assertJsonPath('data.theme', $theme);
            $this->getJson('/api/auth/me')->assertOk()
                ->assertJsonPath('appearance.theme', $theme)
                ->assertJsonPath('preferences.theme', 'dark');
            $this->assertDatabaseHas('installation_appearance', ['id' => 1, 'theme' => $theme, 'updated_by' => $user->id]);
            $this->assertDatabaseCount('installation_appearance', 1);
        }

        // A fresh resolver reads the persisted record rather than per-request state.
        $this->assertSame('ink-flow', (new InstallationAppearance)->theme());
    }

    public static function invalidThemePayloads(): array
    {
        return [
            'absent' => [[]],
            'null' => [['theme' => null]],
            'empty' => [['theme' => '']],
            'unknown' => [['theme' => 'corporate']],
            'world category' => [['theme' => 'worlds']],
            'localized world category' => [['theme' => 'ambientazioni']],
            'classic category' => [['theme' => 'classic']],
            'identity category' => [['theme' => 'identity']],
            'abstract category' => [['theme' => 'abstract']],
            'all categories filter' => [['theme' => 'all']],
            'wrong case' => [['theme' => 'Luxury']],
            'world wrong case' => [['theme' => 'Polar']],
            'array' => [['theme' => ['luxury']]],
            'number' => [['theme' => 1]],
            'attribute injection' => [['theme' => '"><script>alert(1)</script>']],
            'css location' => [['theme' => 'https://example.com/theme.css']],
            'local css location' => [['theme' => '/editorial/themes/polar/theme.css']],
            'css traversal' => [['theme' => '../../theme.css']],
        ];
    }

    #[DataProvider('invalidThemePayloads')]
    public function test_invalid_payloads_cannot_replace_the_active_theme(array $payload): void
    {
        AppearanceRecord::create(['id' => 1, 'theme' => 'luxury']);
        $this->actingAsWithoutTenant($this->user('super-admin'));

        $this->putJson('/api/system/appearance', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('theme');
        $this->assertDatabaseHas('installation_appearance', ['id' => 1, 'theme' => 'luxury', 'updated_by' => null]);
        $this->assertDatabaseCount('installation_appearance', 1);
    }

    public function test_selected_theme_is_shared_by_all_tenants_and_does_not_change_light_dark_preferences(): void
    {
        AppearanceRecord::create(['id' => 1, 'theme' => 'ink-flow']);
        $this->actingAsWithoutTenant($this->user('viewer'));

        foreach (['company-a', 'company-b'] as $tenant) {
            $this->getJson('/api/auth/me', ['X-Tenant-Id' => $tenant])
                ->assertOk()
                ->assertJsonPath('appearance.theme', 'ink-flow')
                ->assertJsonPath('preferences.theme', 'dark');
            $this->getJson('/api/appearance', ['X-Tenant-Id' => $tenant])
                ->assertOk()->assertExactJson(['data' => ['theme' => 'ink-flow']]);
        }
    }

    public function test_extra_tenant_project_or_actor_fields_cannot_change_the_global_write_scope(): void
    {
        $user = $this->user('super-admin');
        $this->actingAsWithoutTenant($user);

        $this->putJson('/api/system/appearance', [
            'theme' => 'dynamic-office', 'tenant_id' => 'forged', 'project_key' => 'forged',
            'id' => 2, 'updated_by' => 999999,
        ], ['X-Tenant-Id' => 'unassociated-company'])
            ->assertOk()->assertExactJson(['data' => ['theme' => 'dynamic-office']]);
        $this->assertDatabaseHas('installation_appearance', ['id' => 1, 'theme' => 'dynamic-office', 'updated_by' => $user->id]);
        $this->assertDatabaseCount('installation_appearance', 1);
    }

    public function test_corrupt_or_obsolete_persisted_values_fall_back_to_the_safe_default(): void
    {
        AppearanceRecord::create(['id' => 1, 'theme' => '"><script>bad</script>']);
        $this->getJson('/api/appearance')->assertOk()->assertExactJson(['data' => ['theme' => 'humanistic']]);
        $this->withoutVite();
        $this->get('/landing')->assertOk()->assertSee('data-design-theme="humanistic"', false)->assertDontSee('<script>bad</script>', false);
    }

    #[DataProvider('designThemes')]
    public function test_guest_landing_and_all_auth_shells_receive_the_selected_theme_before_react_mounts(string $theme): void
    {
        AppearanceRecord::create(['id' => 1, 'theme' => $theme]);
        $this->withoutVite();

        $this->getJson('/api/appearance')->assertOk()->assertExactJson(['data' => ['theme' => $theme]]);

        foreach (['/landing', '/app/me', '/login', '/register', '/forgot-password', '/reset-password?token=test&email=reader%40example.com'] as $path) {
            $this->get($path)->assertOk()->assertSee('data-design-theme="'.$theme.'"', false);
        }
    }

    public function test_missing_migration_preserves_guest_bootstrap_but_rejects_writes_explicitly(): void
    {
        Schema::drop('installation_appearance');
        $this->getJson('/api/appearance')->assertOk()->assertExactJson(['data' => ['theme' => 'humanistic']]);
        $this->withoutVite();
        $this->get('/login')->assertOk()->assertSee('data-design-theme="humanistic"', false);
        $this->actingAsWithoutTenant($this->user('super-admin'));
        $this->putJson('/api/system/appearance', ['theme' => 'luxury'])
            ->assertStatus(503)->assertJsonPath('message', 'Installation appearance storage is not available.');
    }

    public function test_schema_database_failure_preserves_public_bootstrap_and_landing_with_a_reported_default(): void
    {
        Exceptions::fake();
        $error = new QueryException('sqlite', 'select schema', [], new PDOException('Database unavailable'));
        Schema::shouldReceive('hasTable')->with('installation_appearance')->twice()->andThrow($error);

        $this->getJson('/api/appearance')->assertOk()->assertExactJson(['data' => ['theme' => 'humanistic']]);
        $this->withoutVite();
        $this->get('/landing')->assertOk()->assertSee('data-design-theme="humanistic"', false);

        Exceptions::assertReported(fn (QueryException $reported): bool => $reported === $error);
    }

    public function test_record_lookup_database_failure_preserves_public_bootstrap_and_landing_with_a_reported_default(): void
    {
        AppearanceRecord::create(['id' => 1, 'theme' => 'luxury']);
        $this->assertTrue(Schema::hasTable('installation_appearance'));
        Exceptions::fake();
        $error = new QueryException('sqlite', 'select theme from installation_appearance', [], new PDOException('Database unavailable'));
        DB::connection()->beforeExecuting(static function (string $query) use ($error): void {
            if (str_contains($query, '"installation_appearance"')) {
                throw $error;
            }
        });

        $this->getJson('/api/appearance')->assertOk()->assertExactJson(['data' => ['theme' => 'humanistic']]);
        $this->withoutVite();
        $this->get('/landing')->assertOk()->assertSee('data-design-theme="humanistic"', false);

        Exceptions::assertReported(fn (QueryException $reported): bool => $reported === $error);
    }

    public function test_database_failures_during_writes_are_not_converted_into_successful_defaults(): void
    {
        $actor = $this->user('super-admin');
        $error = new QueryException('sqlite', 'select schema', [], new PDOException('Database unavailable'));
        Schema::shouldReceive('hasTable')->with('installation_appearance')->once()->andThrow($error);

        $this->expectExceptionObject($error);

        app(InstallationAppearance::class)->setTheme('luxury', $actor);
    }

    public function test_unexpected_non_database_errors_are_not_hidden_by_the_theme_fallback(): void
    {
        $error = new RuntimeException('Unexpected application failure');
        Schema::shouldReceive('hasTable')->with('installation_appearance')->once()->andThrow($error);

        $this->expectExceptionObject($error);

        app(InstallationAppearance::class)->theme();
    }

    public function test_a_desktop_bearer_token_cannot_use_its_owners_super_admin_role_to_manage_appearance(): void
    {
        $token = $this->user('super-admin')->createToken('desktop', ['kb:read', 'kb:chat'])->plainTextToken;
        $this->withToken($token)->putJson('/api/system/appearance', ['theme' => 'luxury'])
            ->assertForbidden()->assertJsonPath('error', 'token_ability_forbidden');
        $this->assertDatabaseCount('installation_appearance', 0);
    }

    public function test_an_explicitly_scoped_super_admin_bearer_token_can_manage_appearance(): void
    {
        $token = $this->user('super-admin')->createToken('appearance', ['appearance:manage'])->plainTextToken;
        $this->withToken($token)->putJson('/api/system/appearance', ['theme' => 'luxury'])
            ->assertOk()->assertExactJson(['data' => ['theme' => 'luxury']]);
    }

    private function user(string $role): User
    {
        $user = User::create(['name' => 'Appearance tester', 'email' => uniqid('appearance-', true).'@example.com', 'password' => Hash::make('secret123')]);
        $user->assignRole(Role::findOrCreate($role, 'web'));

        return $user;
    }
}

<?php

namespace Tests\Feature\Api\Admin;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * v4.4/W3 Copilot iter 2 finding #2 — Eval Harness UI bootstrap config
 * endpoint.
 *
 * Mirrors the response-shape contract documented on
 * `EvalHarnessUiBootstrapController::show()` so the cross-mounted
 * `padosoft/eval-harness-ui` SPA can rely on the host endpoint replaying
 * `config/eval-harness-ui.php` settings (metric labels, polling
 * intervals, locale, command-palette shortcut).
 *
 * RBAC is asserted explicitly so a future Gate edit doesn't silently
 * widen the audience: viewer + guest are 403 / 401, admin / dpo /
 * editor / super-admin pass.
 */
class EvalHarnessUiBootstrapControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function defineRoutes($router): void
    {
        $router->middleware('api')->prefix('api')->group(__DIR__.'/../../../../routes/api.php');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        Cache::flush();
    }

    public function test_admin_gets_the_bootstrap_payload_replaying_the_host_config(): void
    {
        config()->set('eval-harness-ui.metric_labels', ['macro_f1' => 'Macro F1']);
        config()->set('eval-harness-ui.polling', ['live_batches_seconds' => 7]);
        config()->set('eval-harness-ui.locale', 'en');
        config()->set('eval-harness-ui.tenant_header', 'X-Eval-Harness-Tenant');
        config()->set('eval-harness-ui.assets.command_palette_shortcut', 'mod+k');

        $admin = $this->makeUserWithRole('admin');

        $payload = $this->actingAs($admin)
            ->getJson('/api/admin/eval-harness/bootstrap-config')
            ->assertOk()
            ->json();

        $this->assertSame('0.1.0', $payload['ui_version']);
        $this->assertSame(['macro_f1' => 'Macro F1'], $payload['metric_labels']);
        $this->assertSame('X-Eval-Harness-Tenant', $payload['tenant_header']);
        $this->assertSame(['live_batches_seconds' => 7], $payload['polling']);
        $this->assertSame('en', $payload['locale']);
        $this->assertSame('mod+k', $payload['shortcuts']['commandPalette']);
    }

    public function test_locale_normalises_unsupported_value_to_en(): void
    {
        config()->set('eval-harness-ui.locale', 'pt_BR');
        $admin = $this->makeUserWithRole('admin');

        $payload = $this->actingAs($admin)
            ->getJson('/api/admin/eval-harness/bootstrap-config')
            ->assertOk()
            ->json();

        // The package only ships en + it catalogues; anything else
        // falls back to en so the SPA renders predictable copy.
        $this->assertSame('en', $payload['locale']);
    }

    public function test_locale_normalises_it_dialect_to_it(): void
    {
        config()->set('eval-harness-ui.locale', 'it_IT');
        $admin = $this->makeUserWithRole('admin');

        $payload = $this->actingAs($admin)
            ->getJson('/api/admin/eval-harness/bootstrap-config')
            ->assertOk()
            ->json();

        $this->assertSame('it', $payload['locale']);
    }

    public function test_tenant_header_serialises_as_null_when_blank(): void
    {
        // The package's `AppBootstrapConfig.tenant_header` accepts
        // `null` to disable header forwarding entirely. A bare empty
        // string would be a config bug — surface it as null so the
        // FE doesn't accidentally forward the empty header value.
        config()->set('eval-harness-ui.tenant_header', '');
        $admin = $this->makeUserWithRole('admin');

        $payload = $this->actingAs($admin)
            ->getJson('/api/admin/eval-harness/bootstrap-config')
            ->assertOk()
            ->json();

        $this->assertNull($payload['tenant_header']);
    }

    public function test_dpo_role_passes_the_gate(): void
    {
        $dpo = $this->makeUserWithRole('dpo');

        $this->actingAs($dpo)
            ->getJson('/api/admin/eval-harness/bootstrap-config')
            ->assertOk();
    }

    public function test_editor_role_passes_the_gate(): void
    {
        $editor = $this->makeUserWithRole('editor');

        // The Gate `eval-harness.viewer` admits editor — same Gate
        // that protects the package's blade routes. Asserting OK here
        // pins the role allowlist documented on the route comment so
        // a future Gate-narrowing change has to update this test.
        $this->actingAs($editor)
            ->getJson('/api/admin/eval-harness/bootstrap-config')
            ->assertOk();
    }

    public function test_viewer_role_is_403(): void
    {
        $viewer = $this->makeUserWithRole('viewer');

        $this->actingAs($viewer)
            ->getJson('/api/admin/eval-harness/bootstrap-config')
            ->assertStatus(403);
    }

    public function test_guest_is_401(): void
    {
        $this->getJson('/api/admin/eval-harness/bootstrap-config')
            ->assertStatus(401);
    }

    private function makeUserWithRole(string $role): User
    {
        $user = User::create([
            'name' => ucfirst($role),
            'email' => $role.'-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);
        $user->assignRole($role);

        return $user;
    }
}

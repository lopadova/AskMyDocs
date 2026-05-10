<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Padosoft\PiiRedactorAdmin\Models\PiiRedactorAdminAuditEvent;
use Tests\TestCase;

/**
 * v4.2/W4 sub-PR 5 — Route registration + tenant-stamping observer.
 *
 * Observable contracts:
 *
 *   1. With `pii-redactor-admin.enabled=true`, the admin shell route
 *      AND the JSON status route resolve under the configured prefix
 *      AND every route carries the `can:viewPiiRedactorAdmin`
 *      middleware.
 *   2. With `pii-redactor-admin.enabled=false` (the default), no
 *      `pii-redactor-admin` routes exist at all — a request to the
 *      mount path 404s (R14: explicit failure semantic, never 200
 *      with empty body).
 *   3. The tenant-stamping observer wired in
 *      AppServiceProvider::registerPiiRedactorAdminTenantStamping()
 *      stamps the active TenantContext id on a freshly-created
 *      audit row, even though the package model excludes
 *      `tenant_id` from `$fillable` (R30/R31).
 */
class PiiRedactorAdminMountingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Flip the admin SPA on for THIS test class only. Other test
     * classes (e.g. PiiRedactorAdminGatesTest) keep the default-off
     * behaviour so they exercise the Gate matrix without the route
     * stack mattering.
     *
     * We override `getEnvironmentSetUp` (not `defineEnvironment`)
     * because the package SP's boot() reads
     * `config('pii-redactor-admin.enabled')` and Testbench's hook
     * order in the installed v11 release fires defineEnvironment
     * AFTER the SPs already booted — flipping the flag there
     * would be too late to register the routes. Calling parent
     * first preserves the project-wide test wiring (sqlite, AI
     * config, etc.) and then layers the admin-on override on top.
     */
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('pii-redactor-admin.enabled', true);
        $app['config']->set('pii-redactor-admin.route_prefix', 'admin/pii-redactor');
        $app['config']->set('pii-redactor-admin.api_prefix', 'admin/pii-redactor/api');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_admin_routes_register_under_configured_prefix_when_enabled(): void
    {
        $shellRoute = Route::getRoutes()->getByName('pii-redactor-admin.shell');
        $statusRoute = Route::getRoutes()->getByName('pii-redactor-admin.api.status');

        $this->assertNotNull($shellRoute, 'Expected shell route to be registered when enabled.');
        $this->assertNotNull($statusRoute, 'Expected status JSON route to be registered when enabled.');

        $this->assertStringStartsWith('admin/pii-redactor', $shellRoute->uri());
        $this->assertStringStartsWith('admin/pii-redactor/api', $statusRoute->uri());

        // Every package route carries the configured outer-fence Gate
        // middleware. Defence in depth: even if the SP changes route
        // declaration in a future minor, the Gate still gates HTTP.
        $this->assertContains(
            'can:viewPiiRedactorAdmin',
            $shellRoute->gatherMiddleware(),
            'Expected shell route to be gated by can:viewPiiRedactorAdmin.',
        );
        $this->assertContains(
            'can:viewPiiRedactorAdmin',
            $statusRoute->gatherMiddleware(),
            'Expected status JSON route to be gated by can:viewPiiRedactorAdmin.',
        );
    }

    public function test_audit_row_creation_stamps_active_tenant_id_via_observer(): void
    {
        // Set a non-default tenant so we can prove the observer reads
        // from TenantContext rather than hard-coding 'default'.
        app(TenantContext::class)->set('acme-corp');

        $event = PiiRedactorAdminAuditEvent::query()->create([
            'event_type' => 'redact',
            'actor_id' => 'user:42',
            'ip' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'strategy' => 'mask',
            'total' => 3,
            'counts_json' => ['email' => 2, 'phone' => 1],
            'target_hash' => str_repeat('a', 64),
            'target_ref' => 'inline',
            'status_code' => 200,
            'justification' => null,
        ]);

        // The package model's $fillable excludes tenant_id by design;
        // the column is populated by the AppServiceProvider observer
        // BEFORE the row hits the DB.
        $this->assertSame('acme-corp', $event->fresh()->getAttribute('tenant_id'));
    }

    public function test_audit_observer_preserves_explicit_tenant_id_when_already_set(): void
    {
        app(TenantContext::class)->set('acme-corp');

        $event = new PiiRedactorAdminAuditEvent;
        // Bypass $fillable explicitly — simulating an admin backfill
        // tool that wants to write a row for a different tenant than
        // the active context (e.g. cross-tenant migration).
        $event->setAttribute('tenant_id', 'umbrella-llc');
        $event->event_type = 'redact';
        $event->status_code = 200;
        $event->save();

        $this->assertSame('umbrella-llc', $event->fresh()->getAttribute('tenant_id'));
    }

    public function test_audit_query_is_tenant_scoped_by_default_and_can_opt_out(): void
    {
        app(TenantContext::class)->set('acme-corp');

        $currentTenant = new PiiRedactorAdminAuditEvent;
        $currentTenant->setAttribute('tenant_id', 'acme-corp');
        $currentTenant->event_type = 'redact';
        $currentTenant->status_code = 200;
        $currentTenant->save();

        $otherTenant = new PiiRedactorAdminAuditEvent;
        $otherTenant->setAttribute('tenant_id', 'umbrella-llc');
        $otherTenant->event_type = 'redact';
        $otherTenant->status_code = 200;
        $otherTenant->save();

        $scoped = PiiRedactorAdminAuditEvent::query()
            ->orderBy('tenant_id')
            ->pluck('tenant_id')
            ->all();
        $this->assertSame(['acme-corp'], $scoped);

        $allTenants = PiiRedactorAdminAuditEvent::query()
            ->withoutGlobalScope('tenant')
            ->orderBy('tenant_id')
            ->pluck('tenant_id')
            ->all();
        $this->assertSame(['acme-corp', 'umbrella-llc'], $allTenants);
    }
}

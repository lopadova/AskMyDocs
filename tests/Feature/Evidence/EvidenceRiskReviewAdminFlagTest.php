<?php

declare(strict_types=1);

namespace Tests\Feature\Evidence;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * v8.13/P11 — R43 OFF-path regression for the Evidence & Risk Review admin
 * surface. `EVIDENCE_RISK_REVIEW_ADMIN_ENABLED` defaults to false, so a fresh
 * deploy must ship the admin HTTP API DORMANT: the package never registers the
 * /api/admin/evidence-risk-review/* routes, and a hit on them degrades to a
 * clean 404 — never a 500, never an unauthenticated leak. The native FE
 * cross-mount mirrors this with a clean "unavailable" landing (covered by the
 * Vitest spec). The ON path is covered by EvidenceRiskReviewIntegrationTest +
 * AdminAuthorizationMatrixTest, which run under TestCase's forced ON config.
 */
final class EvidenceRiskReviewAdminFlagTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Re-disable the admin surface AFTER the parent forced it on, so the
     * package boots without registering its route group (the production
     * default state).
     */
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('evidence-risk-review.api.enabled', false);
    }

    protected function defineRoutes($router): void
    {
        $router->middleware('api')->prefix('api')->group(__DIR__.'/../../../routes/api.php');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_admin_api_is_absent_when_the_flag_is_off(): void
    {
        $admin = User::create([
            'name' => 'Super Admin',
            'email' => 'super-admin-'.uniqid().'@demo.local',
            'password' => Hash::make('secret-password'),
        ]);
        $admin->assignRole('super-admin');

        // Flag OFF → the route is never registered → clean 404 for an
        // authenticated, fully-authorised operator (NOT a 500, NOT a 200 with
        // an empty body).
        $this->actingAs($admin)
            ->getJson('/api/admin/evidence-risk-review/reviews')
            ->assertNotFound();
    }
}

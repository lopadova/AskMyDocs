<?php

declare(strict_types=1);

namespace Tests\Feature\TabularReview;

use App\Models\TabularReview;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Cross-tenant isolation for the tabular-review SSE stream (SEC-IDOR-001, F-04).
 *
 * The route `POST /api/admin/tabular-reviews/{id}/generate-stream` carries
 * `auth.sse:sanctum` + `can:viewTabularReviews` but NOT `tenant.authorize`; it
 * relies on the controller's own `forTenant(TenantContext::current())` scoping.
 * These tests lock that in: an admin can never stream a review that belongs to a
 * tenant they are not a member of — neither with no header nor by spoofing
 * `X-Tenant-Id`.
 */
class TabularReviewStreamTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function defineRoutes($router): void
    {
        // Prepend ResolveTenant to mirror production, where it is GLOBAL
        // (bootstrap/app.php) — otherwise X-Tenant-Id would have no effect in
        // Testbench and this suite would not actually exercise header spoofing.
        $router->middleware([\App\Http\Middleware\ResolveTenant::class, 'api'])
            ->prefix('api')
            ->group(__DIR__.'/../../../routes/api.php');
    }

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seed(RbacSeeder::class);
        // A real (non-reserved) tenant so tenant.authorize admits the owner;
        // actingAs() provisions the acting user's membership in it.
        app(TenantContext::class)->set('tenant-a');
    }

    private function makeAdmin(): User
    {
        $user = User::create([
            'name' => 'A',
            'email' => 'a-'.uniqid().'@demo.local',
            'password' => Hash::make('secret'),
        ]);
        $user->assignRole('admin');

        return $user;
    }

    private function makeReviewInTenant(string $tenantId, int $userId): TabularReview
    {
        app(TenantContext::class)->set($tenantId);
        $review = TabularReview::create([
            'project_key' => 'hr',
            'user_id' => $userId,
            'title' => 'cross-tenant review',
            'columns_config' => [['name' => 'X', 'format' => 'text']],
        ]);
        // Restore the acting admin's own tenant so actingAs() provisions
        // membership there (not under a reserved namespace).
        app(TenantContext::class)->set('tenant-a');

        return $review;
    }

    public function test_admin_cannot_stream_a_review_from_another_tenant(): void
    {
        $admin = $this->makeAdmin();
        $review = $this->makeReviewInTenant('tenant-b', $admin->id);

        // No spoof: the request runs in the admin's own tenant ('tenant-a' via
        // actingAs), so tenant-b's review is out of scope → 404.
        $this->actingAs($admin)
            ->postJson('/api/admin/tabular-reviews/'.$review->id.'/generate-stream')
            ->assertStatus(404);
    }

    public function test_admin_cannot_reach_another_tenant_by_spoofing_the_header(): void
    {
        $admin = $this->makeAdmin();
        $review = $this->makeReviewInTenant('tenant-b', $admin->id);

        // The admin is a member of 'tenant-a' only. Spoofing X-Tenant-Id:
        // tenant-b makes ResolveTenant switch TenantContext to tenant-b;
        // tenant.authorize must then reject the request because the admin has no
        // membership in tenant-b. Before the fix this returned 200 and streamed
        // the review.
        $response = $this->actingAs($admin)
            ->withHeaders(['X-Tenant-Id' => 'tenant-b'])
            ->postJson('/api/admin/tabular-reviews/'.$review->id.'/generate-stream');

        $this->assertNotSame(200, $response->getStatusCode(),
            'spoofing X-Tenant-Id must not stream another tenant\'s review');
        $this->assertSame(403, $response->getStatusCode(),
            'tenant.authorize must deny an admin with no membership in the spoofed tenant');
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\TabularReview;

use App\Models\TabularCell;
use App\Models\TabularReview;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * v4.7/W1 — R30 cross-tenant isolation on the tabular-review surface.
 *
 * Verifies:
 *  - Reviews created under tenant A do NOT surface under tenant B's
 *    GET /tabular-reviews.
 *  - Show / update / destroy under tenant B against tenant A's review
 *    id 404 (cannot mutate cross-tenant rows).
 *  - tenant_id auto-filled from TenantContext at create time.
 *  - Cells inherit the parent review's tenant on insert.
 *
 * HTTP requests carry `X-Tenant-Id: ...` so ResolveTenant middleware
 * pins the context to the test's tenant before the controller runs.
 *
 * v8.0.3 (C1) — switching the active tenant via X-Tenant-Id now requires
 * the `tenant.cross-access` permission (AuthorizeTenantHeader rejects a
 * foreign header with 403 otherwise). The cross-tenant actor here is
 * therefore a super-admin; the isolation guarantee under test
 * (forTenant scoping → cross-tenant rows are 404/invisible) is
 * unchanged and orthogonal to the actor's role.
 */
final class TabularReviewTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function defineRoutes($router): void
    {
        $router->middleware('api')->prefix('api')->group(__DIR__.'/../../../routes/api.php');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_tenant_a_review_is_invisible_to_tenant_b_listing(): void
    {
        $admin = $this->makeAdmin();

        $this->withTenant('tenant-a', function () use ($admin) {
            TabularReview::create([
                'project_key' => 'hr',
                'user_id' => $admin->id,
                'title' => 'A',
                'columns_config' => [['name' => 'X', 'format' => 'text']],
            ]);
        });

        $resp = $this->actingAs($admin)
            ->withHeaders(['X-Tenant-Id' => 'tenant-b'])
            ->getJson('/api/admin/tabular-reviews');

        $resp->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_tenant_b_404s_on_tenant_a_review_id(): void
    {
        $admin = $this->makeAdmin();

        $review = $this->withTenant('tenant-a', fn () => TabularReview::create([
            'project_key' => 'hr',
            'user_id' => $admin->id,
            'title' => 'A',
            'columns_config' => [['name' => 'X', 'format' => 'text']],
        ]));

        $this->actingAs($admin)
            ->withHeaders(['X-Tenant-Id' => 'tenant-b'])
            ->getJson("/api/admin/tabular-reviews/{$review->id}")
            ->assertStatus(404);
    }

    public function test_tenant_b_cannot_delete_tenant_a_review(): void
    {
        $admin = $this->makeAdmin();

        $review = $this->withTenant('tenant-a', fn () => TabularReview::create([
            'project_key' => 'hr',
            'user_id' => $admin->id,
            'title' => 'A',
            'columns_config' => [['name' => 'X', 'format' => 'text']],
        ]));

        $this->actingAs($admin)
            ->withHeaders(['X-Tenant-Id' => 'tenant-b'])
            ->deleteJson("/api/admin/tabular-reviews/{$review->id}")
            ->assertStatus(404);

        // Still present under tenant-a's scope.
        $this->assertDatabaseHas('tabular_reviews', [
            'id' => $review->id,
            'tenant_id' => 'tenant-a',
        ]);
    }

    public function test_review_tenant_id_auto_filled_from_context(): void
    {
        $admin = $this->makeAdmin();

        $review = $this->withTenant('tenant-alpha', fn () => TabularReview::create([
            'project_key' => 'hr',
            'user_id' => $admin->id,
            'title' => 'X',
            'columns_config' => [['name' => 'Y', 'format' => 'text']],
        ]));

        $this->assertSame('tenant-alpha', $review->fresh()->tenant_id);
    }

    public function test_cell_tenant_id_auto_filled_from_context(): void
    {
        $admin = $this->makeAdmin();

        [$review, $doc] = $this->withTenant('tenant-omega', function () use ($admin) {
            $review = TabularReview::create([
                'project_key' => 'hr',
                'user_id' => $admin->id,
                'title' => 'X',
                'columns_config' => [['name' => 'Y', 'format' => 'text']],
            ]);
            $doc = \App\Models\KnowledgeDocument::create([
                'project_key' => 'hr',
                'source_type' => 'markdown',
                'title' => 'd',
                'source_path' => 'd-'.uniqid().'.md',
                'document_hash' => str_repeat('a', 64),
                'version_hash' => str_repeat('b', 64),
                'metadata' => [],
                'status' => 'indexed',
            ]);
            return [$review, $doc];
        });

        $cell = $this->withTenant('tenant-omega', fn () => TabularCell::create([
            'review_id' => $review->id,
            'document_id' => $doc->id,
            'column_index' => 0,
            'content' => ['summary' => 'x', 'flag' => 'green', 'reasoning' => '', 'citations' => []],
            'status' => 'ready',
            'flag' => 'green',
        ]));

        $this->assertSame('tenant-omega', $cell->fresh()->tenant_id);
    }

    private function withTenant(string $tenant, \Closure $callback): mixed
    {
        /** @var TenantContext $ctx */
        $ctx = app(TenantContext::class);
        $previous = $ctx->current();
        $ctx->set($tenant);
        try {
            return $callback();
        } finally {
            $ctx->set($previous);
        }
    }

    private function makeAdmin(): User
    {
        $u = User::create([
            'name' => 'A',
            'email' => 'a-'.uniqid().'@demo.local',
            'password' => Hash::make('secret'),
        ]);
        // super-admin holds tenant.cross-access, the only role allowed to
        // operate across tenants via the X-Tenant-Id header (C1).
        $u->assignRole('super-admin');
        return $u;
    }
}

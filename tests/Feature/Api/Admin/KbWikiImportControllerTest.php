<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Admin;

use App\Jobs\IngestDocumentJob;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * v8.38/W4c (ADR 0032 §11) — single-document import HTTP surface.
 * Auth/RBAC boundary itself is covered by the R32 matrix's own
 * `test_create_import_requires_admin_or_super_admin()`; this suite covers
 * the response shapes (R43 disabled, invalid frontmatter, happy path).
 */
final class KbWikiImportControllerTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    protected function defineRoutes($router): void
    {
        $router->middleware('api')->prefix('api')->group(__DIR__.'/../../../../routes/api.php');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = app(TenantContext::class)->current();
        $this->seed(RbacSeeder::class);
        Storage::fake('kb');
        Queue::fake();
        $this->withHeaders(['Accept' => 'application/json']);
        config(['kb.wiki_export.enabled' => true]);
    }

    private function makeAdmin(): User
    {
        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);
        $admin->assignRole('admin');
        ProjectMembership::create([
            'tenant_id' => $this->tenantId,
            'user_id' => $admin->id,
            'project_key' => 'default',
            'role' => 'member',
        ]);

        return $admin;
    }

    private function markdown(string $slug): string
    {
        return <<<MD
        ---
        id: {$slug}-id
        slug: {$slug}
        type: runbook
        status: accepted
        ---

        # Brand new

        Body.
        MD;
    }

    public function test_it_returns_the_disabled_shape_when_the_flag_is_off(): void
    {
        config(['kb.wiki_export.enabled' => false]);
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->postJson('/api/admin/kb/imports', ['project_key' => 'default', 'markdown' => $this->markdown('x')])
            ->assertOk()
            ->assertJson(['disabled' => true]);
    }

    public function test_it_returns_422_for_invalid_frontmatter(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->postJson('/api/admin/kb/imports', ['project_key' => 'default', 'markdown' => "# Just a heading\n\nNo frontmatter."])
            ->assertStatus(422)
            ->assertJsonPath('status', 'invalid');
    }

    public function test_it_proposes_a_candidate_on_the_happy_path(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)
            ->postJson('/api/admin/kb/imports', ['project_key' => 'default', 'markdown' => $this->markdown('brand-new-page')])
            ->assertStatus(202);

        $this->assertSame('created', $response->json('data.status'));
        $this->assertNotNull($response->json('data.approval'));

        Storage::disk('kb')->assertMissing('runbooks/brand-new-page.md');
        Queue::assertNotPushed(IngestDocumentJob::class);
    }

    public function test_it_validates_required_fields(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->postJson('/api/admin/kb/imports', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['project_key', 'markdown']);
    }
}

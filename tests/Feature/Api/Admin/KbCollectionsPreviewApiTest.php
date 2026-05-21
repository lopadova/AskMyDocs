<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Admin;

use App\Models\KbCollection;
use App\Models\KnowledgeDocument;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class KbCollectionsPreviewApiTest extends TestCase
{
    use RefreshDatabase;

    protected function defineRoutes($router): void
    {
        $router->middleware('api')->prefix('api')->group(__DIR__.'/../../../../routes/api.php');
    }

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set('tenant-a');
        $this->seed(RbacSeeder::class);
    }

    public function test_preview_count_decreases_when_threshold_increases(): void
    {
        $admin = $this->makeAdmin();
        $this->makeCollection('tenant-a', 'preview');

        $this->makeDocument('tenant-a', 'hr', 'hr-policy', ['hr']);
        $this->makeDocument('tenant-a', 'hr', 'hr-onboarding', ['ops']);
        $this->makeDocument('tenant-a', 'ops', 'ops-runbook', ['hr']);
        $this->makeDocument('tenant-a', 'ops', 'ops-sre', ['ops']);

        $payload = [
            'criteria' => [
                'projects' => ['hr'],
                'tags' => ['hr'],
            ],
            'semantic_prompt' => null,
        ];

        $low = $this->actingAs($admin)->postJson('/api/admin/kb/collections/preview', [
            ...$payload,
            'threshold' => 0.5,
        ]);
        $low->assertOk()->assertJsonPath('data.included_count', 3);

        $high = $this->actingAs($admin)->postJson('/api/admin/kb/collections/preview', [
            ...$payload,
            'threshold' => 0.9,
        ]);
        $high->assertOk()->assertJsonPath('data.included_count', 1);
    }

    public function test_preview_is_tenant_scoped(): void
    {
        $admin = $this->makeAdmin();
        $this->makeCollection('tenant-a', 'tenant-scope');

        $this->makeDocument('tenant-a', 'hr', 'tenant-a-doc', ['hr']);
        $this->makeDocument('tenant-b', 'hr', 'tenant-b-doc', ['hr']);

        $response = $this->actingAs($admin)->postJson('/api/admin/kb/collections/preview', [
            'criteria' => [
                'projects' => ['hr'],
                'tags' => ['hr'],
            ],
            'semantic_prompt' => null,
            'threshold' => 0.9,
        ]);

        $response->assertOk()->assertJsonPath('data.included_count', 1);
    }

    private function makeAdmin(): User
    {
        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);
        $admin->assignRole('admin');

        return $admin;
    }

    private function makeCollection(string $tenantId, string $slug): KbCollection
    {
        return KbCollection::query()->create([
            'tenant_id' => $tenantId,
            'slug' => $slug,
            'name' => ucfirst($slug),
            'description' => null,
            'visibility' => 'private',
            'criteria' => [],
            'semantic_prompt' => null,
            'threshold' => 0.75,
        ]);
    }

    /**
     * @param  list<string>  $tags
     */
    private function makeDocument(string $tenantId, string $projectKey, string $slug, array $tags): KnowledgeDocument
    {
        return KnowledgeDocument::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenantId,
            'project_key' => $projectKey,
            'source_type' => 'markdown',
            'title' => $slug,
            'source_path' => "docs/{$slug}.md",
            'language' => 'en',
            'access_scope' => 'internal',
            'status' => 'active',
            'document_hash' => hash('sha256', $slug),
            'version_hash' => hash('sha256', 'v1-'.$slug),
            'metadata' => ['tags' => $tags],
            'indexed_at' => now(),
            'is_canonical' => true,
            'slug' => $slug,
            'canonical_type' => 'decision',
            'canonical_status' => 'accepted',
            'frontmatter_json' => ['tags' => $tags],
        ]);
    }
}


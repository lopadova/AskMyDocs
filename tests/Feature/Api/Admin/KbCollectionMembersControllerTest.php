<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Admin;

use App\Jobs\EvaluateCollectionsJob;
use App\Models\KbCollection;
use App\Models\KbCollectionMember;
use App\Models\KnowledgeDocument;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class KbCollectionMembersControllerTest extends TestCase
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
    }

    public function test_manual_add_creates_member_row(): void
    {
        app(TenantContext::class)->set('tenant-a');
        $admin = $this->makeAdmin();
        $collection = $this->makeCollection('tenant-a');
        $document = $this->makeDocument('tenant-a', 'hr', 'doc-a.md');

        $response = $this->actingAs($admin)->postJson("/api/admin/kb/collections/{$collection->id}/members", [
            'knowledge_document_id' => $document->id,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.collection_id', $collection->id)
            ->assertJsonPath('data.knowledge_document_id', $document->id)
            ->assertJsonPath('data.reason', 'manual')
            ->assertJsonPath('data.manually_excluded', false);

        $this->assertDatabaseHas('kb_collection_members', [
            'tenant_id' => 'tenant-a',
            'collection_id' => $collection->id,
            'knowledge_document_id' => $document->id,
            'reason' => 'manual',
            'manually_excluded' => 0,
        ]);
    }

    public function test_manual_remove_marks_member_as_excluded_and_evaluator_keeps_it_muted(): void
    {
        app(TenantContext::class)->set('tenant-a');
        $admin = $this->makeAdmin();
        $collection = $this->makeCollection('tenant-a', [
            'criteria' => ['projects' => ['hr']],
        ]);
        $document = $this->makeDocument('tenant-a', 'hr', 'doc-b.md');

        $this->actingAs($admin)->postJson("/api/admin/kb/collections/{$collection->id}/members", [
            'knowledge_document_id' => $document->id,
        ])->assertStatus(201);

        $this->actingAs($admin)
            ->deleteJson("/api/admin/kb/collections/{$collection->id}/members/{$document->id}")
            ->assertStatus(204);

        $this->assertDatabaseHas('kb_collection_members', [
            'tenant_id' => 'tenant-a',
            'collection_id' => $collection->id,
            'knowledge_document_id' => $document->id,
            'manually_excluded' => 1,
        ]);

        $this->app->call([new EvaluateCollectionsJob($document->id, 'tenant-a'), 'handle']);

        $member = KbCollectionMember::query()
            ->where('tenant_id', 'tenant-a')
            ->where('collection_id', $collection->id)
            ->where('knowledge_document_id', $document->id)
            ->first();

        $this->assertNotNull($member);
        $this->assertTrue((bool) $member->manually_excluded);
        $this->assertSame('manual', $member->reason);
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

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function makeCollection(string $tenantId, array $overrides = []): KbCollection
    {
        return KbCollection::query()->create(array_merge([
            'tenant_id' => $tenantId,
            'slug' => 'team-docs-'.uniqid(),
            'name' => 'Team Docs',
            'description' => 'Collection for tests',
            'visibility' => 'private',
            'criteria' => [],
            'semantic_prompt' => null,
            'threshold' => 0.8,
        ], $overrides));
    }

    private function makeDocument(string $tenantId, string $projectKey, string $sourcePath): KnowledgeDocument
    {
        return KnowledgeDocument::query()->create([
            'tenant_id' => $tenantId,
            'project_key' => $projectKey,
            'source_type' => 'markdown',
            'title' => 'Manual membership document',
            'source_path' => $sourcePath,
            'document_hash' => str_repeat('a', 64),
            'version_hash' => str_repeat('b', 64),
            'metadata' => [],
            'status' => 'indexed',
            'canonical_type' => null,
            'slug' => null,
            'frontmatter_json' => ['tags' => ['hr']],
        ]);
    }
}

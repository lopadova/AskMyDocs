<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Admin;

use App\Models\KbDocAnalysis;
use App\Models\KnowledgeDocument;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * v8.7/W3–W4 — read-only admin listing of AI document-change analyses.
 */
final class KbDocAnalysisControllerTest extends TestCase
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

    private function makeAnalysis(string $project, int $docId, string $status = 'completed'): KbDocAnalysis
    {
        return KbDocAnalysis::create([
            'project_key' => $project,
            'knowledge_document_id' => $docId,
            'doc_slug' => 'doc-'.$docId,
            'trigger' => KbDocAnalysis::TRIGGER_INGESTED,
            'analysis_json' => ['enhancement_suggestions' => ['Add detail'], 'cross_references' => [], 'impacted_docs' => []],
            'suggestion_count' => 1,
            'impacted_count' => 0,
            'status' => $status,
        ]);
    }

    public function test_index_lists_analyses_with_doc_title(): void
    {
        $admin = $this->makeAdmin();
        $doc = KnowledgeDocument::create([
            'project_key' => 'eng', 'source_type' => 'markdown', 'title' => 'Caching',
            'source_path' => 'c.md', 'document_hash' => str_repeat('a', 64),
            'version_hash' => str_repeat('b', 64), 'metadata' => [], 'status' => 'active',
        ]);
        $this->makeAnalysis('eng', $doc->id);

        $resp = $this->actingAs($admin)->getJson('/api/admin/kb/analyses');

        $resp->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.document_title', 'Caching')
            ->assertJsonPath('data.0.suggestion_count', 1);
    }

    public function test_index_filters_by_project_and_status(): void
    {
        $admin = $this->makeAdmin();
        $this->makeAnalysis('eng', 1, 'completed');
        $this->makeAnalysis('hr', 2, 'failed');

        $this->actingAs($admin)->getJson('/api/admin/kb/analyses?project_keys[]=eng')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.project_key', 'eng');

        $this->actingAs($admin)->getJson('/api/admin/kb/analyses?status=failed')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'failed');
    }

    public function test_non_admin_gets_403(): void
    {
        $viewer = User::create([
            'name' => 'Viewer', 'email' => 'v-'.uniqid().'@demo.local', 'password' => Hash::make('secret'),
        ]);
        $viewer->assignRole('viewer');

        $this->actingAs($viewer)->getJson('/api/admin/kb/analyses')->assertStatus(403);
    }

    public function test_guest_gets_401(): void
    {
        $this->getJson('/api/admin/kb/analyses')->assertStatus(401);
    }

    private function makeAdmin(): User
    {
        $admin = User::create([
            'name' => 'Admin', 'email' => 'admin-'.uniqid().'@demo.local', 'password' => Hash::make('secret123'),
        ]);
        $admin->assignRole('admin');

        return $admin;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\KbEdge;
use App\Models\KbNode;
use App\Models\KnowledgeDocument;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * v8.8/W6 — chat-side related-graph endpoint (`GET /api/kb/related`).
 *
 * Consumer-side (auth:sanctum); returns 1-hop neighbours of cited canonical
 * docs. Validation, auth, empty-when-no-graph.
 */
final class KbGraphControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function defineRoutes($router): void
    {
        $router->middleware('api')->prefix('api')->group(__DIR__.'/../../../routes/api.php');
    }

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->reset();
        config()->set('kb.graph.expansion_enabled', true);
    }

    private function user(bool $withEngAccess = true): User
    {
        $user = User::create([
            'name' => 'Asker',
            'email' => 'asker-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);
        if ($withEngAccess) {
            // Title resolution respects AccessScopeScope — the user must have
            // project access for neighbour titles to resolve.
            ProjectMembership::create([
                'user_id' => $user->id, 'project_key' => 'eng', 'role' => 'member', 'scope_allowlist' => null,
            ]);
        }

        return $user;
    }

    private function seedDoc(string $slug, string $title): void
    {
        KnowledgeDocument::create([
            'project_key' => 'eng', 'source_type' => 'markdown', 'title' => $title,
            'source_path' => "decisions/$slug.md", 'language' => 'en', 'access_scope' => 'internal',
            'status' => 'active', 'document_hash' => hash('sha256', $slug), 'version_hash' => hash('sha256', $slug.'v'),
            'doc_id' => strtoupper($slug), 'slug' => $slug, 'canonical_type' => 'decision',
            'canonical_status' => 'accepted', 'is_canonical' => true,
        ]);
        KbNode::create([
            'node_uid' => $slug, 'node_type' => 'decision', 'label' => $title,
            'project_key' => 'eng', 'source_doc_id' => strtoupper($slug), 'payload_json' => ['dangling' => false],
        ]);
    }

    public function test_returns_related_neighbours(): void
    {
        $this->seedDoc('dec-cache', 'Cache decision');
        $this->seedDoc('dec-redis', 'Redis decision');
        KbEdge::create([
            'edge_uid' => 'dec-cache->dec-redis:depends_on', 'from_node_uid' => 'dec-cache',
            'to_node_uid' => 'dec-redis', 'edge_type' => 'depends_on', 'project_key' => 'eng',
            'source_doc_id' => 'DEC-CACHE', 'weight' => 0.9, 'provenance' => 'wikilink',
        ]);

        $resp = $this->actingAs($this->user())->getJson('/api/kb/related?project_key=eng&slugs[]=dec-cache');

        $resp->assertOk()
            ->assertJsonPath('meta.count', 1)
            ->assertJsonPath('related.0.slug', 'dec-redis')
            ->assertJsonPath('related.0.title', 'Redis decision')
            ->assertJsonPath('related.0.direction', 'outgoing');
    }

    public function test_empty_when_no_graph(): void
    {
        $this->seedDoc('lonely', 'Lonely');

        $resp = $this->actingAs($this->user())->getJson('/api/kb/related?project_key=eng&slugs[]=lonely');

        $resp->assertOk()->assertJsonPath('meta.count', 0)->assertJsonPath('related', []);
    }

    public function test_neighbour_title_is_null_when_user_lacks_access(): void
    {
        // ACL-safe: the related panel must not leak titles of docs the user
        // cannot access — the neighbour slug surfaces, the title stays null.
        $this->seedDoc('dec-cache', 'Cache decision');
        $this->seedDoc('dec-redis', 'Redis decision');
        KbEdge::create([
            'edge_uid' => 'dec-cache->dec-redis:depends_on', 'from_node_uid' => 'dec-cache',
            'to_node_uid' => 'dec-redis', 'edge_type' => 'depends_on', 'project_key' => 'eng',
            'source_doc_id' => 'DEC-CACHE', 'weight' => 0.9, 'provenance' => 'wikilink',
        ]);

        $resp = $this->actingAs($this->user(withEngAccess: false))
            ->getJson('/api/kb/related?project_key=eng&slugs[]=dec-cache');

        $resp->assertOk()
            ->assertJsonPath('related.0.slug', 'dec-redis')
            ->assertJsonPath('related.0.title', null);
    }

    public function test_validation_requires_slugs(): void
    {
        $this->actingAs($this->user())->getJson('/api/kb/related?project_key=eng')->assertStatus(422);
    }

    public function test_guest_is_unauthenticated(): void
    {
        $this->getJson('/api/kb/related?project_key=eng&slugs[]=x')->assertStatus(401);
    }
}

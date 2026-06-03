<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Conversation;
use App\Models\KbEdge;
use App\Models\KbNode;
use App\Models\KnowledgeDocument;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Database\Seeder;

/**
 * v8.8/W6 — seed a PERSISTED conversation whose assistant message cites a
 * canonical doc, plus that doc's 1-hop graph neighbour, so the chat-side
 * "Related" panel E2E (`chat-related.spec.ts`) can drive the panel against
 * REAL data (R13) without a live LLM. Idempotent.
 *
 * Loads on top of DemoSeeder (the `seeded` auto-fixture), reusing its
 * `admin@demo.local` user so the conversation is owned by the authenticated
 * Playwright admin.
 */
final class KbChatGraphSeeder extends Seeder
{
    public function run(): void
    {
        app(TenantContext::class)->set('default');

        // Fail LOUDLY (not a silent early return) when the prerequisite admin
        // is missing: /testing/seed returns 200 on a no-op, so seedDb() would
        // think it succeeded and the E2E would proceed against empty data and
        // time out opaquely (Copilot). Always run after DemoSeeder.
        $admin = User::where('email', 'admin@demo.local')->firstOrFail();

        $project = 'hr-portal';
        $cacheDoc = $this->seedCanonical('dec-cache-graph', 'Cache decision (graph)', $project);
        $this->seedCanonical('dec-redis-graph', 'Redis decision (graph)', $project);

        KbEdge::updateOrCreate(
            ['tenant_id' => 'default', 'project_key' => $project, 'edge_uid' => 'dec-cache-graph->dec-redis-graph:depends_on'],
            [
                'from_node_uid' => 'dec-cache-graph', 'to_node_uid' => 'dec-redis-graph',
                'edge_type' => 'depends_on', 'source_doc_id' => 'DEC-CACHE-GRAPH',
                'weight' => 0.9, 'provenance' => 'wikilink',
            ],
        );

        $conversation = Conversation::updateOrCreate(
            ['tenant_id' => 'default', 'user_id' => $admin->id, 'title' => 'Cache architecture (graph demo)'],
            ['project_key' => $project],
        );
        // Reset its messages so the seeder is idempotent.
        $conversation->messages()->delete();

        $conversation->messages()->create([
            'tenant_id' => 'default', 'role' => 'user', 'content' => 'What did we decide for caching?',
        ]);
        $conversation->messages()->create([
            'tenant_id' => 'default', 'role' => 'assistant',
            'content' => 'We chose a cache layer; see the cited decision.',
            'confidence' => 88,
            'metadata' => [
                'provider' => 'seed', 'model' => 'seed', 'chunks_count' => 1, 'latency_ms' => 10,
                'tool_calls_count' => 0, 'tool_calls' => [], 'confidence' => 88,
                'citations' => [[
                    'document_id' => $cacheDoc->id,
                    'title' => 'Cache decision (graph)',
                    'source_path' => 'decisions/dec-cache-graph.md',
                    'slug' => 'dec-cache-graph',
                    'project_key' => $project,
                    'origin' => 'primary',
                ]],
            ],
        ]);
    }

    private function seedCanonical(string $slug, string $title, string $project): KnowledgeDocument
    {
        $doc = KnowledgeDocument::withTrashed()->updateOrCreate(
            ['tenant_id' => 'default', 'project_key' => $project, 'source_path' => "decisions/$slug.md"],
            [
                'source_type' => 'markdown', 'title' => $title, 'language' => 'en',
                'access_scope' => 'internal', 'status' => 'active',
                'document_hash' => hash('sha256', $slug), 'version_hash' => hash('sha256', $slug.'v'),
                'doc_id' => strtoupper($slug), 'slug' => $slug, 'canonical_type' => 'decision',
                'canonical_status' => 'accepted', 'is_canonical' => true, 'retrieval_priority' => 70,
            ],
        );
        KbNode::updateOrCreate(
            ['tenant_id' => 'default', 'project_key' => $project, 'node_uid' => $slug],
            ['node_type' => 'decision', 'label' => $title, 'source_doc_id' => strtoupper($slug), 'payload_json' => ['dangling' => false]],
        );

        return $doc;
    }
}

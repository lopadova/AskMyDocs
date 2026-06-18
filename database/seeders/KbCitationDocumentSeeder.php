<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Conversation;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Database\Seeder;

/**
 * Seed a PERSISTED conversation whose assistant message cites a canonical doc
 * that HAS chunks, so the chat "open source" modal E2E
 * (`chat-citation-modal.spec.ts`) can click the citation and read REAL content
 * (R13) — `GET /api/kb/documents/{id}/preview` reconstructs the body from those
 * chunks, so the cited doc MUST carry chunks (unlike KbChatGraphSeeder, whose
 * cited doc is chunk-less because the Related-panel E2E never opens it).
 *
 * Loads on top of DemoSeeder (the `seeded` auto-fixture), reusing its
 * `admin@demo.local` user so the conversation is owned by the authenticated
 * Playwright admin. Idempotent.
 */
final class KbCitationDocumentSeeder extends Seeder
{
    private const PROJECT = 'hr-portal';

    private const SLUG = 'dec-source-modal';

    /** The two chunks the preview endpoint reconstructs into the modal body. */
    private const CHUNKS = [
        'We chose Redis as the cache backend with a 1 hour TTL for hot read endpoints.',
        'Cache keys are derived from the request signature; invalidation is event-driven.',
    ];

    public function run(): void
    {
        app(TenantContext::class)->set('default');

        // Fail LOUDLY if the prerequisite admin is missing (always run after
        // DemoSeeder): /testing/seed returns 200 on a no-op, so a silent early
        // return would let the E2E proceed against empty data and time out.
        $admin = User::where('email', 'admin@demo.local')->firstOrFail();

        // Include version_hash in the search key so a stale soft-deleted version
        // with a different version_hash is never accidentally updated in place.
        $doc = KnowledgeDocument::withTrashed()->updateOrCreate(
            [
                'tenant_id' => 'default',
                'project_key' => self::PROJECT,
                'source_path' => 'decisions/' . self::SLUG . '.md',
                'version_hash' => hash('sha256', self::SLUG . 'v'),
            ],
            [
                'source_type' => 'markdown', 'title' => 'Cache backend decision', 'language' => 'en',
                'access_scope' => 'internal', 'status' => 'active',
                'document_hash' => hash('sha256', self::SLUG),
                'doc_id' => strtoupper(str_replace('-', '_', self::SLUG)), 'slug' => self::SLUG,
                'canonical_type' => 'decision', 'canonical_status' => 'accepted',
                'is_canonical' => true, 'retrieval_priority' => 70,
            ],
        );

        // Reset chunks so the body is deterministic across re-seeds.
        $doc->chunks()->delete();
        foreach (self::CHUNKS as $order => $text) {
            KnowledgeChunk::create([
                'tenant_id' => 'default',
                'knowledge_document_id' => $doc->id,
                'project_key' => self::PROJECT,
                'chunk_order' => $order,
                'chunk_hash' => hash('sha256', $text . $order),
                'heading_path' => 'Decision',
                'chunk_text' => $text,
                'metadata' => [],
            ]);
        }

        $conversation = Conversation::updateOrCreate(
            ['tenant_id' => 'default', 'user_id' => $admin->id, 'title' => 'Source modal demo'],
            ['project_key' => self::PROJECT],
        );
        $conversation->messages()->delete();

        $conversation->messages()->create([
            'tenant_id' => 'default', 'role' => 'user', 'content' => 'What did we decide for caching?',
        ]);
        $conversation->messages()->create([
            'tenant_id' => 'default', 'role' => 'assistant',
            'content' => 'We chose a Redis cache layer; open the cited decision for the details.',
            'confidence' => 90,
            'metadata' => [
                'provider' => 'seed', 'model' => 'seed', 'chunks_count' => 2, 'latency_ms' => 10,
                'tool_calls_count' => 0, 'tool_calls' => [], 'confidence' => 90,
                'citations' => [[
                    'document_id' => $doc->id,
                    'title' => 'Cache backend decision',
                    'source_path' => 'decisions/' . self::SLUG . '.md',
                    'slug' => self::SLUG,
                    'project_key' => self::PROJECT,
                    'origin' => 'primary',
                ]],
            ],
        ]);
    }
}

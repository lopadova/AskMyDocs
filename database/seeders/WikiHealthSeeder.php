<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\KbNode;
use App\Models\KnowledgeDocument;
use Illuminate\Database\Seeder;

/**
 * v8.11/P10 — seed a project with a deterministically UNHEALTHY Auto-Wiki graph
 * so the "Wiki Health" Playwright happy path can assert the lint report + the
 * safe auto-fix against REAL data (R13), without driving any LLM.
 *
 * Creates project `eng` with: one real canonical doc + self-node, and a
 * LEFTOVER dangling node (no incoming edge) the auto-fix will prune. The doc's
 * self-node has no edges, so it also surfaces as an orphan; and there is no
 * `kb_wiki_indices` row, so missing_index is true — three finding categories.
 * Idempotent.
 */
final class WikiHealthSeeder extends Seeder
{
    public function run(): void
    {
        KnowledgeDocument::updateOrCreate(
            ['tenant_id' => 'default', 'project_key' => 'eng', 'source_path' => 'decisions/dec-cache.md', 'version_hash' => 'wikihealth-v1'],
            [
                'source_type' => 'markdown', 'title' => 'Cache decision', 'mime_type' => 'text/markdown',
                'status' => 'active', 'document_hash' => str_repeat('a', 64), 'is_canonical' => true,
                'doc_id' => 'dec-cache', 'slug' => 'dec-cache', 'canonical_type' => 'decision',
                'canonical_status' => 'accepted', 'generation_source' => 'human', 'indexed_at' => now(),
            ],
        );

        KbNode::updateOrCreate(
            ['tenant_id' => 'default', 'project_key' => 'eng', 'node_uid' => 'dec-cache'],
            ['node_type' => 'decision', 'label' => 'Cache decision', 'source_doc_id' => 'dec-cache', 'payload_json' => ['dangling' => false]],
        );

        // Leftover dangling node with no incoming edge → the safe auto-fix prunes it.
        KbNode::updateOrCreate(
            ['tenant_id' => 'default', 'project_key' => 'eng', 'node_uid' => 'ghost-leftover'],
            ['node_type' => 'unknown', 'label' => 'ghost-leftover', 'source_doc_id' => null, 'payload_json' => ['dangling' => true]],
        );
    }
}

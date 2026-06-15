<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\KnowledgeDocument;
use Illuminate\Database\Seeder;

/**
 * v8.11/P10 — seed a project with two slugged canonical docs (one human, one
 * auto domain-concept) so the "Wiki Indices" Playwright happy path can build the
 * index from REAL data (R13): click Rebuild → the hub roll-up appears with
 * page_total=2, auto_count=1, human_count=1, and a graph_rebuild operation logs.
 *
 * No KbWikiIndex rows are seeded — the screen starts EMPTY and the rebuild
 * mutation compiles the map, exercising the real POST + GET round trip.
 * Idempotent.
 */
final class WikiIndicesSeeder extends Seeder
{
    public function run(): void
    {
        KnowledgeDocument::updateOrCreate(
            ['tenant_id' => 'default', 'project_key' => 'eng', 'source_path' => 'decisions/dec-cache.md', 'version_hash' => 'wikiidx-v1'],
            [
                'source_type' => 'markdown', 'title' => 'Cache decision', 'mime_type' => 'text/markdown',
                'status' => 'active', 'document_hash' => str_repeat('b', 64), 'is_canonical' => true,
                'doc_id' => 'dec-cache', 'slug' => 'dec-cache', 'canonical_type' => 'decision',
                'canonical_status' => 'accepted', 'generation_source' => 'human', 'indexed_at' => now(),
            ],
        );

        KnowledgeDocument::updateOrCreate(
            ['tenant_id' => 'default', 'project_key' => 'eng', 'source_path' => 'domain-concepts/caching.md', 'version_hash' => 'wikiidx-v1'],
            [
                'source_type' => 'markdown', 'title' => 'Caching', 'mime_type' => 'text/markdown',
                'status' => 'active', 'document_hash' => str_repeat('c', 64), 'is_canonical' => true,
                'doc_id' => 'concept-caching', 'slug' => 'concept-caching', 'canonical_type' => 'domain-concept',
                'canonical_status' => 'accepted', 'generation_source' => 'auto', 'indexed_at' => now(),
            ],
        );
    }
}

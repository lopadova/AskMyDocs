<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\KnowledgeDocument;
use App\Support\Canonical\GenerationSource;
use Illuminate\Database\Seeder;

/**
 * v8.11/P10 — seed a project with one AUTO wiki page and one HUMAN page so the
 * Wiki Explorer Playwright happy path can assert tier badges + promote an auto
 * page against REAL data (R13). After promoting, the auto page becomes human and
 * the row flips to read-only on refetch. Idempotent.
 */
final class WikiExplorerSeeder extends Seeder
{
    public function run(): void
    {
        KnowledgeDocument::updateOrCreate(
            ['tenant_id' => 'default', 'project_key' => 'eng', 'source_path' => 'domain-concepts/auto-cache.md', 'version_hash' => 'wikiexp-v1'],
            [
                'source_type' => 'markdown', 'title' => 'Caching (auto)', 'mime_type' => 'text/markdown',
                'status' => 'active', 'document_hash' => str_repeat('1', 64), 'is_canonical' => true,
                'doc_id' => 'auto-cache', 'slug' => 'auto-cache', 'canonical_type' => 'domain-concept',
                'canonical_status' => 'review', 'generation_source' => GenerationSource::Auto->value, 'indexed_at' => now(),
            ],
        );

        KnowledgeDocument::updateOrCreate(
            ['tenant_id' => 'default', 'project_key' => 'eng', 'source_path' => 'decisions/dec-human.md', 'version_hash' => 'wikiexp-v1'],
            [
                'source_type' => 'markdown', 'title' => 'Human decision', 'mime_type' => 'text/markdown',
                'status' => 'active', 'document_hash' => str_repeat('2', 64), 'is_canonical' => true,
                'doc_id' => 'dec-human', 'slug' => 'dec-human', 'canonical_type' => 'decision',
                'canonical_status' => 'accepted', 'generation_source' => GenerationSource::Human->value, 'indexed_at' => now(),
            ],
        );
    }
}

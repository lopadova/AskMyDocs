<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\KbDocAnalysis;
use App\Models\KnowledgeDocument;
use Illuminate\Database\Seeder;

/**
 * v8.11/P10 — seed one completed `modified`-trigger analysis whose
 * cross-reference can be APPLIED against REAL data (R13), without running the
 * async AnalyzeDocumentChangeJob or the external AI provider.
 *
 * The source doc (`dec-cache`) resolves with a slug, and the analysis suggests a
 * cross-reference to `runbook-cache`. A manual apply (admin actor) bypasses the
 * auto-only firewall, so the cross-reference apply returns `applied: true` and
 * creates a real `kb_edges` row. Idempotent.
 */
final class KbApplySuggestionSeeder extends Seeder
{
    public function run(): void
    {
        $doc = KnowledgeDocument::updateOrCreate(
            ['tenant_id' => 'default', 'project_key' => 'eng', 'source_path' => 'decisions/dec-cache.md', 'version_hash' => 'apply-v1'],
            [
                'source_type' => 'markdown', 'title' => 'Cache decision', 'mime_type' => 'text/markdown',
                'status' => 'active', 'document_hash' => str_repeat('f', 64), 'is_canonical' => true,
                'doc_id' => 'dec-cache', 'slug' => 'dec-cache', 'canonical_type' => 'decision',
                'canonical_status' => 'accepted', 'generation_source' => 'human', 'indexed_at' => now(),
            ],
        );

        KbDocAnalysis::updateOrCreate(
            ['tenant_id' => 'default', 'knowledge_document_id' => $doc->id, 'trigger' => KbDocAnalysis::TRIGGER_MODIFIED],
            [
                'project_key' => 'eng',
                'doc_slug' => 'dec-cache',
                'analysis_json' => [
                    'enhancement_suggestions' => ['Document the eviction policy.'],
                    'cross_references' => [
                        ['slug' => 'runbook-cache', 'title' => 'Cache runbook', 'why' => 'operationalises this decision'],
                    ],
                    'impacted_docs' => [],
                ],
                'suggestion_count' => 1,
                'impacted_count' => 0,
                'provider' => 'seed',
                'model' => 'seed-model',
                'status' => KbDocAnalysis::STATUS_COMPLETED,
            ],
        );
    }
}

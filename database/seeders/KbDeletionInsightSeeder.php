<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\KbDocAnalysis;
use App\Models\KnowledgeDocument;
use Illuminate\Database\Seeder;

/**
 * v8.8/W2 — seed ONE `deleted`-trigger AI analysis so the Doc Insights
 * Playwright happy path can assert the deletion-impact card renders against
 * REAL data (R13), without running the async AnalyzeDocumentDeletionJob or
 * the external AI provider.
 *
 * Seeds a soft-deleted canonical document (so its title still resolves via
 * the controller's `withTrashed()` lookup) plus a completed `kb_doc_analyses`
 * row carrying an impacted-doc the deletion left with a dangling reference.
 * Idempotent — keyed on (tenant, project, source_path).
 */
final class KbDeletionInsightSeeder extends Seeder
{
    public function run(): void
    {
        $doc = KnowledgeDocument::withTrashed()->updateOrCreate(
            [
                'tenant_id' => 'default',
                'project_key' => 'eng',
                'source_path' => 'decisions/dec-cache-v1.md',
            ],
            [
                'source_type' => 'markdown',
                'title' => 'Cache decision v1 (removed)',
                'language' => 'en',
                'access_scope' => 'internal',
                'status' => 'active',
                'document_hash' => str_pad('d', 64, 'd'),
                'version_hash' => str_pad('e', 64, 'e'),
                'doc_id' => 'DEC-CACHE-1',
                'slug' => 'dec-cache-v1',
                'canonical_type' => 'decision',
                'canonical_status' => 'accepted',
                'is_canonical' => true,
                'retrieval_priority' => 70,
            ],
        );

        // Soft-delete it: the document is gone from read paths but its title
        // still resolves for the analysis listing.
        if (! $doc->trashed()) {
            $doc->delete();
        }

        KbDocAnalysis::updateOrCreate(
            [
                'tenant_id' => 'default',
                'knowledge_document_id' => $doc->id,
                'trigger' => KbDocAnalysis::TRIGGER_DELETED,
            ],
            [
                'project_key' => 'eng',
                'doc_slug' => 'dec-cache-v1',
                'analysis_json' => [
                    'enhancement_suggestions' => [],
                    'cross_references' => [
                        ['slug' => 'runbook-cache', 'title' => 'Cache runbook', 'why' => 'linked the deleted decision'],
                    ],
                    'impacted_docs' => [
                        [
                            'slug' => 'runbook-cache',
                            'title' => 'Cache runbook',
                            'impact' => 'dangling reference to the deleted decision',
                            'suggested_action' => 'update: drop the link to dec-cache-v1',
                        ],
                    ],
                ],
                'suggestion_count' => 0,
                'impacted_count' => 1,
                'provider' => 'seed',
                'model' => 'seed-model',
                'status' => KbDocAnalysis::STATUS_COMPLETED,
            ],
        );
    }
}

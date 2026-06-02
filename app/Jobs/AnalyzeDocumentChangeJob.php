<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\KbDocAnalysis;
use App\Models\KnowledgeDocument;
use App\Notifications\NotificationPublisher;
use App\Services\Kb\Analysis\ChangeAnalysisGate;
use App\Services\Kb\Analysis\KbChangeAnalyzer;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * v8.7/W3–W4 — async AI deep-analysis of a document change.
 *
 * Dispatched by {@see IngestDocumentJob} AFTER the ingest flow has
 * persisted the document + chunks (so the analyzer has content to read).
 * Cost-gated by config: ON for canonical docs by default, OFF (opt-in) for
 * non-canonical. Debounced so rapid re-ingests don't re-analyse the same
 * doc within a window. Suggest-only — it writes a `kb_doc_analyses` row and
 * fires `KbDocAnalysisReady`, never mutating the doc (ADR 0003).
 */
final class AnalyzeDocumentChangeJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    /** @var array<int,int> */
    public array $backoff = [30, 120];

    public function __construct(
        public readonly int $documentId,
        public readonly string $tenantId,
    ) {
        $this->onQueue(config('kb.change_analysis.queue', 'default'));
    }

    public function handle(
        TenantContext $tenants,
        KbChangeAnalyzer $analyzer,
        NotificationPublisher $publisher,
        ChangeAnalysisGate $gate,
    ): void {
        $previousTenant = $tenants->current();
        try {
            $tenants->set($this->tenantId);

            $document = KnowledgeDocument::query()
                ->forTenant($this->tenantId)
                ->find($this->documentId);
            if ($document === null) {
                return; // doc deleted between dispatch and run
            }

            // v8.8/W3 — gate resolves config + per-(tenant, project) override.
            if (! $gate->allows($this->tenantId, (string) $document->project_key, (bool) $document->is_canonical)) {
                return;
            }
            if ($this->recentlyAnalysed($document)) {
                return; // debounce — already analysed this window
            }

            $trigger = $this->resolveTrigger($document);
            $this->runAnalysis($analyzer, $publisher, $document, $trigger);
        } finally {
            $tenants->set($previousTenant);
        }
    }

    private function runAnalysis(
        KbChangeAnalyzer $analyzer,
        NotificationPublisher $publisher,
        KnowledgeDocument $document,
        string $trigger,
    ): void {
        try {
            $result = $analyzer->analyze($document, $trigger);
            $analysis = $result['analysis'];

            $row = KbDocAnalysis::create([
                'project_key' => (string) $document->project_key,
                'knowledge_document_id' => (int) $document->id,
                'doc_slug' => $document->slug,
                'trigger' => $trigger,
                'analysis_json' => $analysis,
                'suggestion_count' => count($analysis['enhancement_suggestions']),
                'impacted_count' => count($analysis['impacted_docs']),
                'provider' => $result['provider'],
                'model' => $result['model'],
                'status' => KbDocAnalysis::STATUS_COMPLETED,
            ]);

            $publisher->publishKbDocAnalysisReady(
                $document,
                (int) $row->id,
                (int) $row->suggestion_count,
                (int) $row->impacted_count,
            );
        } catch (Throwable $e) {
            // R14 — a failed analysis is recorded, not swallowed, so the
            // admin surface can show "analysis failed" rather than nothing.
            KbDocAnalysis::create([
                'project_key' => (string) $document->project_key,
                'knowledge_document_id' => (int) $document->id,
                'doc_slug' => $document->slug,
                'trigger' => $trigger,
                'analysis_json' => ['enhancement_suggestions' => [], 'cross_references' => [], 'impacted_docs' => []],
                'status' => KbDocAnalysis::STATUS_FAILED,
                'error' => mb_substr($e->getMessage(), 0, 1000),
            ]);
            Log::warning('AnalyzeDocumentChangeJob: analysis failed', [
                'document_id' => $this->documentId,
                'tenant_id' => $this->tenantId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function recentlyAnalysed(KnowledgeDocument $document): bool
    {
        $minutes = (int) config('kb.change_analysis.debounce_minutes', 60);
        if ($minutes <= 0) {
            return false;
        }

        // Key the debounce on a STABLE identifier, not the per-version row
        // id: a re-ingest with changed content creates a NEW
        // knowledge_documents row (new id, same project + canonical slug),
        // so debouncing by id would never catch the rapid-re-ingest cost
        // scenario it exists to guard (Copilot review). Canonical docs key
        // on (project_key, doc_slug); non-canonical (no slug) fall back to
        // the row id.
        $query = KbDocAnalysis::query()
            ->forTenant($this->tenantId)
            ->where('created_at', '>=', now()->subMinutes($minutes));

        if (! empty($document->slug)) {
            $query->where('project_key', (string) $document->project_key)
                ->where('doc_slug', (string) $document->slug);
        } else {
            $query->where('knowledge_document_id', $document->id);
        }

        return $query->exists();
    }

    /**
     * `modified` when a prior version of the same (tenant, project,
     * source_path) exists (archived by the latest ingest), else `ingested`.
     */
    private function resolveTrigger(KnowledgeDocument $document): string
    {
        $hasPrior = KnowledgeDocument::query()
            ->forTenant($this->tenantId)
            ->where('project_key', $document->project_key)
            ->where('source_path', $document->source_path)
            ->where('id', '!=', $document->id)
            ->exists();

        return $hasPrior ? KbDocAnalysis::TRIGGER_MODIFIED : KbDocAnalysis::TRIGGER_INGESTED;
    }
}

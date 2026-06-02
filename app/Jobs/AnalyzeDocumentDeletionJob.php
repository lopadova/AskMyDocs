<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\KbDocAnalysis;
use App\Models\KnowledgeDocument;
use App\Notifications\NotificationPublisher;
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
 * v8.8/W2 — async AI deep-analysis of a document DELETION.
 *
 * Dispatched by {@see \App\Services\Kb\DocumentDeleter} (only for the
 * user-initiated single delete — never bulk orphan/prune sweeps) with a
 * pre-delete SNAPSHOT, because the document row + its chunks are gone after
 * a hard delete and hidden after a soft delete. Assesses the OBSOLESCENCE
 * IMPACT on the documents that referenced the deleted one and records the
 * advice in `kb_doc_analyses` (`trigger='deleted'`), firing
 * `KbDocAnalysisReady`. Suggest-only — it never mutates anything (ADR 0003).
 *
 * Cost-gated identically to {@see AnalyzeDocumentChangeJob}: ON for canonical
 * docs by default, opt-in for non-canonical. (v8.8/W3 unifies both gates into
 * a per-tenant/per-project override.)
 */
final class AnalyzeDocumentDeletionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    /** @var array<int,int> */
    public array $backoff = [30, 120];

    /**
     * @param  array{tenant_id: string, project_key: string, knowledge_document_id: int, doc_slug: ?string, title: string, source_path: string, is_canonical: bool, doc_text: string}  $snapshot
     */
    public function __construct(
        public readonly array $snapshot,
    ) {
        $this->onQueue(config('kb.change_analysis.queue', 'default'));
    }

    public function handle(
        TenantContext $tenants,
        KbChangeAnalyzer $analyzer,
        NotificationPublisher $publisher,
    ): void {
        if (! (bool) config('kb.change_analysis.enabled', true)) {
            return;
        }
        if (! (bool) config('kb.change_analysis.delete_enabled', true)) {
            return;
        }

        $tenantId = (string) ($this->snapshot['tenant_id'] ?? '');
        if ($tenantId === '') {
            return;
        }

        $previousTenant = $tenants->current();
        try {
            $tenants->set($tenantId);

            if (! $this->enabledFor((bool) ($this->snapshot['is_canonical'] ?? false))) {
                return;
            }

            $this->runAnalysis($analyzer, $publisher, $tenantId);
        } finally {
            $tenants->set($previousTenant);
        }
    }

    private function runAnalysis(
        KbChangeAnalyzer $analyzer,
        NotificationPublisher $publisher,
        string $tenantId,
    ): void {
        $documentId = (int) ($this->snapshot['knowledge_document_id'] ?? 0);
        $projectKey = (string) ($this->snapshot['project_key'] ?? '');
        $docSlug = $this->snapshot['doc_slug'] ?? null;

        try {
            $result = $analyzer->analyzeDeletion($this->snapshot);
            $analysis = $result['analysis'];

            $row = KbDocAnalysis::create([
                'project_key' => $projectKey,
                'knowledge_document_id' => $documentId,
                'doc_slug' => $docSlug,
                'trigger' => KbDocAnalysis::TRIGGER_DELETED,
                'analysis_json' => $analysis,
                'suggestion_count' => count($analysis['enhancement_suggestions']),
                'impacted_count' => count($analysis['impacted_docs']),
                'provider' => $result['provider'],
                'model' => $result['model'],
                'status' => KbDocAnalysis::STATUS_COMPLETED,
            ]);

            $publisher->publishKbDocAnalysisReady(
                $this->subjectDocument($tenantId, $documentId),
                (int) $row->id,
                (int) $row->suggestion_count,
                (int) $row->impacted_count,
            );
        } catch (Throwable $e) {
            // R14 — a failed analysis is recorded, not swallowed, so the
            // admin surface can show "analysis failed" rather than nothing.
            KbDocAnalysis::create([
                'project_key' => $projectKey,
                'knowledge_document_id' => $documentId,
                'doc_slug' => $docSlug,
                'trigger' => KbDocAnalysis::TRIGGER_DELETED,
                'analysis_json' => ['enhancement_suggestions' => [], 'cross_references' => [], 'impacted_docs' => []],
                'status' => KbDocAnalysis::STATUS_FAILED,
                'error' => mb_substr($e->getMessage(), 0, 1000),
            ]);
            Log::warning('AnalyzeDocumentDeletionJob: analysis failed', [
                'document_id' => $documentId,
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The deleted document for recipient resolution. A SOFT delete leaves the
     * row reachable via withTrashed(); a HARD delete removes it entirely, so
     * we hydrate a transient (unsaved) model from the snapshot — enough for
     * the publisher to resolve tenant + project + ACL recipients.
     */
    private function subjectDocument(string $tenantId, int $documentId): KnowledgeDocument
    {
        $existing = KnowledgeDocument::withTrashed()
            ->forTenant($tenantId)
            ->find($documentId);
        if ($existing !== null) {
            return $existing;
        }

        $document = new KnowledgeDocument();
        $document->id = $documentId;
        $document->tenant_id = $tenantId;
        $document->project_key = (string) ($this->snapshot['project_key'] ?? '');
        $document->title = (string) ($this->snapshot['title'] ?? '');
        $document->slug = $this->snapshot['doc_slug'] ?? null;
        // source_path is load-bearing for the publisher's scope_allowlist
        // folder-glob ACL check — without it a scoped project member could be
        // wrongly excluded (Copilot review). Carry it from the snapshot.
        $document->source_path = (string) ($this->snapshot['source_path'] ?? '');

        return $document;
    }

    private function enabledFor(bool $isCanonical): bool
    {
        return $isCanonical
            ? (bool) config('kb.change_analysis.canonical_default', true)
            : (bool) config('kb.change_analysis.non_canonical_default', false);
    }
}

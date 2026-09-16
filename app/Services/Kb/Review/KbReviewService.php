<?php

declare(strict_types=1);

namespace App\Services\Kb\Review;

use App\Exceptions\KbReviewDisabledException;
use App\Models\KbCanonicalAudit;
use App\Models\KbDocumentPageReview;
use App\Models\KnowledgeDocument;
use App\Services\Kb\AutoWiki\WikiExplorerService;
use App\Support\Canonical\GenerationSource;
use Illuminate\Support\Facades\DB;

/**
 * v8.37/W3 (ADR 0031) — the shared core (R44) behind Digitization Review:
 * per-page review progress and document approval (the `auto -> human`
 * transition, ADR 0014, branched on canonicity per §4). Every mutating
 * method throws {@see KbReviewDisabledException} when the feature flag
 * (`kb.review.enabled`, `KB_DIGITIZATION_REVIEW_ENABLED`) is off (R43) —
 * never a silent no-op.
 *
 * Approval on a CANONICAL document delegates to
 * {@see WikiExplorerService::promote()}, which already performs the exact
 * same transition (audited, transactional) for its own callers. A
 * NON-canonical document (the ordinary OCR'd-scan case) gets the identical
 * generation_source flip and audit event, implemented directly here rather
 * than by relaxing WikiExplorerService::promote() — that method
 * unconditionally sets canonical_status='accepted', which is correct for
 * its existing (canonical-only) callers and would stamp a false canonical
 * fact on a non-canonical row.
 */
class KbReviewService
{
    public function __construct(
        private readonly WikiExplorerService $wikiExplorer,
    ) {}

    /**
     * Upsert a page's review state on the (tenant, document, page) unique
     * key — re-marking an already-reviewed page is an idempotent no-op with
     * a fresh reviewed_by/reviewed_at, never a second row (ADR 0031 §2).
     *
     * @throws \InvalidArgumentException  page_number is 1-based (ADR 0031
     *     §2); this is the application-layer guard every surface (CLI now,
     *     HTTP/MCP in a later W3 sub-branch) funnels through (R44's "one
     *     core"). Postgres additionally enforces it with a CHECK constraint
     *     as defense-in-depth (Copilot PR #494); SQLite cannot ALTER TABLE
     *     ADD a CHECK after creation, so this guard is the ONLY enforcement
     *     under the test driver.
     */
    public function markPageReviewed(KnowledgeDocument $document, int $pageNumber, string $actor): KbDocumentPageReview
    {
        $this->assertEnabled();

        if ($pageNumber < 1) {
            throw new \InvalidArgumentException("page_number must be >= 1, got {$pageNumber}.");
        }

        return KbDocumentPageReview::query()->updateOrCreate(
            [
                'tenant_id' => (string) $document->tenant_id,
                'knowledge_document_id' => $document->id,
                'page_number' => $pageNumber,
            ],
            [
                'status' => KbDocumentPageReview::STATUS_REVIEWED,
                'reviewed_by' => $this->resolveUserId($actor),
                'reviewed_at' => now(),
            ],
        );
    }

    /**
     * Derived counts (never a cached boolean, ADR 0031 §2) — there is
     * nothing to keep in sync when a correction re-chunks the document into
     * a different page count.
     *
     * @return array{total: int, reviewed: int, unreviewed: int}
     */
    public function documentReviewSummary(KnowledgeDocument $document): array
    {
        $counts = KbDocumentPageReview::query()
            ->where('tenant_id', (string) $document->tenant_id)
            ->where('knowledge_document_id', $document->id)
            ->selectRaw('status, count(*) as aggregate_count')
            ->groupBy('status')
            ->pluck('aggregate_count', 'status');

        $reviewed = (int) ($counts[KbDocumentPageReview::STATUS_REVIEWED] ?? 0);
        $unreviewed = (int) ($counts[KbDocumentPageReview::STATUS_UNREVIEWED] ?? 0);

        return [
            'total' => $reviewed + $unreviewed,
            'reviewed' => $reviewed,
            'unreviewed' => $unreviewed,
        ];
    }

    /**
     * Approve a document — the `auto -> human` transition, branched on
     * canonicity (ADR 0031 §4). Both branches write exactly one
     * `kb_canonical_audit` row (`event_type = 'promoted'`) in the SAME
     * transaction as the column flip; no "approved but unaudited" state is
     * reachable. Refuses (without error) a document already `human`, so a
     * double-click / double-call is a safe no-op — same posture as
     * WikiExplorerService::promote().
     *
     * @return array{approved: bool, reason?: string}
     */
    public function approve(KnowledgeDocument $document, string $actor): array
    {
        $this->assertEnabled();

        if ((bool) $document->is_canonical) {
            $result = $this->wikiExplorer->promote($document, $actor);

            return [
                'approved' => (bool) ($result['promoted'] ?? false),
                'reason' => $result['reason'] ?? null,
            ];
        }

        if ((string) ($document->generation_source ?? GenerationSource::Human->value) !== GenerationSource::Auto->value) {
            return ['approved' => false, 'reason' => 'not_auto'];
        }

        $tenantId = (string) $document->tenant_id;
        $before = ['generation_source' => (string) $document->generation_source];

        DB::transaction(function () use ($document, $tenantId, $actor, $before): void {
            $document->forceFill(['generation_source' => GenerationSource::Human->value])->save();

            if ((bool) config('kb.canonical.audit_enabled', true)) {
                KbCanonicalAudit::create([
                    'tenant_id' => $tenantId,
                    'project_key' => (string) $document->project_key,
                    'doc_id' => $document->doc_id,
                    'slug' => $document->slug,
                    'event_type' => 'promoted',
                    'actor' => $actor,
                    'before_json' => $before,
                    'after_json' => ['generation_source' => GenerationSource::Human->value],
                    'metadata_json' => ['source' => 'kb_review_approve_non_canonical'],
                ]);
            }
        });

        return ['approved' => true];
    }

    private function assertEnabled(): void
    {
        if (! (bool) config('kb.review.enabled', false)) {
            throw new KbReviewDisabledException();
        }
    }

    /**
     * `reviewed_by` is a nullable FK to `users`. Only an actor shaped
     * `user:{id}` resolves to one; a CLI/system/agent actor leaves it null
     * rather than guessing an owning user.
     */
    private function resolveUserId(string $actor): ?int
    {
        if (preg_match('/^user:(\d+)$/', $actor, $m) === 1) {
            return (int) $m[1];
        }

        return null;
    }
}

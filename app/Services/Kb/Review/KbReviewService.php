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
     * R21 (Copilot PR #494 round 3) — a plain `updateOrCreate()` is a SELECT
     * then an INSERT/UPDATE, not one atomic statement: two reviewers marking
     * the SAME page concurrently can both miss the row on the SELECT and
     * both attempt an INSERT, so one of them would hit the unique
     * constraint as an uncaught exception instead of the promised
     * idempotent upsert. `Model::upsert()` compiles to the database's
     * native single-statement upsert (`INSERT ... ON CONFLICT DO UPDATE` /
     * `ON DUPLICATE KEY UPDATE`), so the database itself — not two round
     * trips from PHP — resolves the race; the loser's insert becomes the
     * update, never an exception.
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

        $tenantId = (string) $document->tenant_id;

        KbDocumentPageReview::query()->upsert(
            [
                [
                    'tenant_id' => $tenantId,
                    'knowledge_document_id' => $document->id,
                    'page_number' => $pageNumber,
                    'status' => KbDocumentPageReview::STATUS_REVIEWED,
                    'reviewed_by' => $this->resolveUserId($actor),
                    'reviewed_at' => now(),
                ],
            ],
            ['tenant_id', 'knowledge_document_id', 'page_number'],
            ['status', 'reviewed_by', 'reviewed_at'],
        );

        return KbDocumentPageReview::query()
            ->where('tenant_id', $tenantId)
            ->where('knowledge_document_id', $document->id)
            ->where('page_number', $pageNumber)
            ->firstOrFail();
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
     * R21 (Copilot PR #494 round 2) — the whole method runs inside ONE
     * transaction that `lockForUpdate()`s the tenant-scoped document row
     * FIRST and re-reads `is_canonical` / `generation_source` from that
     * locked row, not from the `$document` argument the caller passed in
     * (which may be stale by the time this runs). This serializes
     * concurrent approve() calls on the SAME document — including the
     * canonical branch, which delegates to
     * {@see WikiExplorerService::promote()} INSIDE the held lock: promote()
     * opens its own (nested, savepoint-backed) transaction and reads/writes
     * the row while this method still holds the outer row lock, so a second
     * concurrent approve() blocks on the SELECT ... FOR UPDATE until the
     * first one commits, then re-reads the now-`human` row and returns the
     * safe `not_auto` no-op instead of racing to a duplicate audit row.
     *
     * @return array{approved: bool, reason?: string}
     */
    public function approve(KnowledgeDocument $document, string $actor): array
    {
        $this->assertEnabled();

        $tenantId = (string) $document->tenant_id;
        $documentId = (int) $document->id;

        return DB::transaction(function () use ($tenantId, $documentId, $actor): array {
            /** @var KnowledgeDocument $locked */
            $locked = KnowledgeDocument::query()
                ->forTenant($tenantId)
                ->lockForUpdate()
                ->findOrFail($documentId);

            if ((bool) $locked->is_canonical) {
                $result = $this->wikiExplorer->promote($locked, $actor);

                return [
                    'approved' => (bool) ($result['promoted'] ?? false),
                    'reason' => $result['reason'] ?? null,
                ];
            }

            if ((string) ($locked->generation_source ?? GenerationSource::Human->value) !== GenerationSource::Auto->value) {
                return ['approved' => false, 'reason' => 'not_auto'];
            }

            $before = ['generation_source' => (string) $locked->generation_source];

            $locked->forceFill(['generation_source' => GenerationSource::Human->value])->save();

            if ((bool) config('kb.canonical.audit_enabled', true)) {
                KbCanonicalAudit::create([
                    'tenant_id' => $tenantId,
                    'project_key' => (string) $locked->project_key,
                    'doc_id' => $locked->doc_id,
                    'slug' => $locked->slug,
                    'event_type' => 'promoted',
                    'actor' => $actor,
                    'before_json' => $before,
                    'after_json' => ['generation_source' => GenerationSource::Human->value],
                    'metadata_json' => ['source' => 'kb_review_approve_non_canonical'],
                ]);
            }

            return ['approved' => true];
        });
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

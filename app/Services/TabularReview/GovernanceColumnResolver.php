<?php

declare(strict_types=1);

namespace App\Services\TabularReview;

use App\Models\KbEdge;
use App\Models\KnowledgeDocument;
use App\Support\Canonical\EdgeType;
use App\Support\Canonical\EvidenceTier;
use App\Support\TabularReview\CellFlag;
use App\Support\TenantContext;
use Illuminate\Support\Carbon;

/**
 * v8.19/W4 — deterministic resolver for `agent: graph` columns.
 *
 * Computes a single governance SIGNAL for one row document directly from the
 * canonical knowledge graph (`kb_edges`) + the document's own governance columns
 * — NO LLM call. This is what powers the "Canonical KB Governance Audit" report:
 * every cell is an auditable, deterministic fact (evidence tier, frontmatter
 * completeness, canonical status, graph connectivity, supersession, staleness),
 * each with a colour flag the FE renders identically to the LLM-extracted cells.
 *
 * R30: every graph query is tenant-scoped via `forTenant()` + the document's
 * `project_key`. R14: an unknown metric or a non-canonical document yields an
 * explicit grey/red cell, never a silent empty.
 *
 * The cell content shape matches the LLM path exactly
 * (`{summary, flag, reasoning, citations}`) so {@see TabularReviewExtractor}
 * persists it through the same path.
 */
class GovernanceColumnResolver
{
    /** Staleness threshold (days) beyond which a doc's freshness flags red. */
    private const STALE_DAYS = 180;

    /** Metrics this resolver understands — the single source of truth for `metric` validation. */
    public const METRICS = [
        'evidence_tier',
        'frontmatter_completeness',
        'canonical_status',
        'is_canonical',
        'incoming_edges',
        'outgoing_edges',
        'graph_connectivity',
        'is_orphan',
        'supersession_status',
        'staleness_days',
    ];

    /** @var array<string, int> request-scoped memo of unfiltered edge counts. */
    private array $edgeCountCache = [];

    public function __construct(private readonly TenantContext $ctx) {}

    /**
     * Resolve a governance metric for the document. Returns the cell content
     * array, or null when the metric is unknown (the caller turns that into a
     * red failure cell, R14).
     *
     * @return array{summary: ?string, flag: string, reasoning: string, citations: array<int, mixed>}|null
     */
    public function resolve(KnowledgeDocument $doc, ?string $metric): ?array
    {
        if ($metric === null || ! in_array($metric, self::METRICS, true)) {
            return null;
        }

        return match ($metric) {
            'evidence_tier' => $this->evidenceTier($doc),
            'frontmatter_completeness' => $this->frontmatterCompleteness($doc),
            'canonical_status' => $this->canonicalStatus($doc),
            'is_canonical' => $this->isCanonical($doc),
            'incoming_edges' => $this->edgeCount($doc, incoming: true),
            'outgoing_edges' => $this->edgeCount($doc, incoming: false),
            'graph_connectivity' => $this->graphConnectivity($doc),
            'is_orphan' => $this->isOrphan($doc),
            'supersession_status' => $this->supersessionStatus($doc),
            'staleness_days' => $this->staleness($doc),
        };
    }

    /** @return array{summary: ?string, flag: string, reasoning: string, citations: array<int, mixed>} */
    private function cell(?string $summary, CellFlag $flag, string $reasoning): array
    {
        return ['summary' => $summary, 'flag' => $flag->value, 'reasoning' => $reasoning, 'citations' => []];
    }

    private function evidenceTier(KnowledgeDocument $doc): array
    {
        // Use the real EvidenceTier taxonomy (guideline/peer_reviewed/official/
        // preprint/news/blog/search_hint/unverified), mapped via its own rank() +
        // isLowConfidence() so the flags track the domain's evidence-strength axis
        // — not a guessed primary/secondary scheme.
        $tier = EvidenceTier::tryFromLoose($doc->evidence_tier);
        if ($tier === null) {
            return $this->cell('unverified', CellFlag::YELLOW, 'No evidence tier assessed on this document.');
        }
        $flag = match (true) {
            $tier->isLowConfidence() => CellFlag::RED,   // blog / search_hint / unverified
            $tier->rank() >= 60 => CellFlag::GREEN,        // guideline / peer_reviewed / official
            default => CellFlag::YELLOW,                    // preprint / news
        };

        return $this->cell($tier->value, $flag, "Evidence tier is '{$tier->value}' (strength {$tier->rank()}).");
    }

    private function frontmatterCompleteness(KnowledgeDocument $doc): array
    {
        if ($doc->is_canonical !== true) {
            return $this->cell('n/a', CellFlag::GREY, 'Non-canonical document — frontmatter not applicable.');
        }
        $fm = is_array($doc->frontmatter_json) ? $doc->frontmatter_json : [];
        $required = ['slug', 'type', 'title'];
        $present = array_filter($required, static fn (string $k): bool => isset($fm[$k]) && $fm[$k] !== '');
        $rate = count($present) / count($required);
        $flag = $rate >= 1.0 ? CellFlag::GREEN : ($rate >= 0.5 ? CellFlag::YELLOW : CellFlag::RED);
        $pct = (int) round($rate * 100);

        return $this->cell("{$pct}%", $flag, sprintf('%d of %d required frontmatter keys present (%s).', count($present), count($required), implode(', ', $required)));
    }

    private function canonicalStatus(KnowledgeDocument $doc): array
    {
        $status = is_string($doc->canonical_status) && $doc->canonical_status !== '' ? $doc->canonical_status : null;
        if ($status === null) {
            return $this->cell('none', CellFlag::GREY, 'Document has no canonical status.');
        }
        $flag = match ($status) {
            'accepted' => CellFlag::GREEN,
            'draft', 'proposed' => CellFlag::YELLOW,
            'deprecated', 'superseded', 'rejected' => CellFlag::RED,
            default => CellFlag::GREY,
        };

        return $this->cell($status, $flag, "Canonical status is '{$status}'.");
    }

    private function isCanonical(KnowledgeDocument $doc): array
    {
        $canonical = $doc->is_canonical === true;

        return $this->cell(
            $canonical ? 'Yes' : 'No',
            $canonical ? CellFlag::GREEN : CellFlag::GREY,
            $canonical ? 'Document is canonical.' : 'Document is not canonical.',
        );
    }

    /** Resolve the graph node uid for this document (canonical docs: node_uid == slug). */
    private function nodeUid(KnowledgeDocument $doc): ?string
    {
        return is_string($doc->slug) && $doc->slug !== '' ? $doc->slug : null;
    }

    /** @param  bool  $incoming  true = edges pointing AT this doc, false = edges FROM it */
    private function edgesQuery(KnowledgeDocument $doc, bool $incoming): \Illuminate\Database\Eloquent\Builder
    {
        $col = $incoming ? 'to_node_uid' : 'from_node_uid';

        return KbEdge::query()
            ->forTenant($this->ctx->current())
            ->where('project_key', $doc->project_key)
            ->where($col, (string) $this->nodeUid($doc));
    }

    /**
     * Unfiltered incoming/outgoing edge count, memoised per
     * (tenant|project|node|direction) so a governance report that asks for
     * several edge-based metrics on the same document (graph_connectivity +
     * is_orphan + incoming/outgoing_edges) issues each COUNT only once.
     */
    private function countEdges(KnowledgeDocument $doc, bool $incoming): int
    {
        $key = $this->ctx->current().'|'.$doc->project_key.'|'.((string) $this->nodeUid($doc)).'|'.($incoming ? 'in' : 'out');

        return $this->edgeCountCache[$key] ??= $this->edgesQuery($doc, $incoming)->count();
    }

    private function edgeCount(KnowledgeDocument $doc, bool $incoming): array
    {
        if ($this->nodeUid($doc) === null) {
            return $this->cell('n/a', CellFlag::GREY, 'Non-canonical document — not present in the graph.');
        }
        $count = $this->countEdges($doc, $incoming);
        $label = $incoming ? 'incoming' : 'outgoing';
        $flag = $count > 0 ? CellFlag::GREEN : CellFlag::YELLOW;

        return $this->cell((string) $count, $flag, "{$count} {$label} graph edge(s).");
    }

    private function graphConnectivity(KnowledgeDocument $doc): array
    {
        if ($this->nodeUid($doc) === null) {
            return $this->cell('n/a', CellFlag::GREY, 'Non-canonical document — not present in the graph.');
        }
        $in = $this->countEdges($doc, incoming: true);
        $out = $this->countEdges($doc, incoming: false);
        $total = $in + $out;
        $flag = $total === 0 ? CellFlag::RED : ($total < 2 ? CellFlag::YELLOW : CellFlag::GREEN);

        return $this->cell((string) $total, $flag, "{$out} outgoing + {$in} incoming = {$total} total edge(s).");
    }

    private function isOrphan(KnowledgeDocument $doc): array
    {
        if ($this->nodeUid($doc) === null) {
            return $this->cell('n/a', CellFlag::GREY, 'Non-canonical document — not present in the graph.');
        }
        $total = $this->countEdges($doc, incoming: true) + $this->countEdges($doc, incoming: false);
        $orphan = $total === 0;

        return $this->cell(
            $orphan ? 'Yes' : 'No',
            $orphan ? CellFlag::RED : CellFlag::GREEN,
            $orphan ? 'Document has no graph edges (orphan).' : "Document is connected ({$total} edge(s)).",
        );
    }

    private function supersessionStatus(KnowledgeDocument $doc): array
    {
        if ($this->nodeUid($doc) === null) {
            return $this->cell('n/a', CellFlag::GREY, 'Non-canonical document — not present in the graph.');
        }
        // Outgoing `supersedes` → this doc supersedes another (current, authoritative).
        $supersedes = $this->edgesQuery($doc, incoming: false)->where('edge_type', EdgeType::Supersedes->value)->count();
        // Incoming `supersedes` → this doc IS superseded by a newer one (stale).
        $supersededBy = $this->edgesQuery($doc, incoming: true)->where('edge_type', EdgeType::Supersedes->value)->count();
        // OUTGOING `invalidated_by` → this doc's own `superseded_by:` frontmatter
        // (the canonical pipeline emits it as from=this-doc → to=invalidator), so
        // THIS doc is the invalidated/superseded one. (An INCOMING invalidated_by
        // means this doc is the invalidator — i.e. current — so it is NOT a red.)
        $invalidated = $this->edgesQuery($doc, incoming: false)->where('edge_type', EdgeType::InvalidatedBy->value)->count();

        if ($invalidated > 0) {
            return $this->cell('invalidated', CellFlag::RED, 'Document declares itself superseded (invalidated_by) by a newer decision.');
        }
        if ($supersededBy > 0) {
            return $this->cell('superseded', CellFlag::RED, "Document is superseded by {$supersededBy} newer doc(s).");
        }
        if ($supersedes > 0) {
            return $this->cell('supersedes', CellFlag::GREEN, "Document supersedes {$supersedes} older doc(s) (current).");
        }

        return $this->cell('current', CellFlag::GREEN, 'No supersession edges — document is current.');
    }

    private function staleness(KnowledgeDocument $doc): array
    {
        $updated = $doc->source_updated_at ?? $doc->updated_at;
        if (! $updated instanceof Carbon) {
            $updated = $updated !== null ? Carbon::parse((string) $updated) : null;
        }
        if ($updated === null) {
            return $this->cell('unknown', CellFlag::GREY, 'No source_updated_at timestamp.');
        }
        $days = (int) $updated->diffInDays(Carbon::now());
        $flag = $days > self::STALE_DAYS ? CellFlag::RED : ($days > self::STALE_DAYS / 2 ? CellFlag::YELLOW : CellFlag::GREEN);

        return $this->cell((string) $days, $flag, "Last updated {$days} day(s) ago (stale beyond ".self::STALE_DAYS.' days).');
    }
}

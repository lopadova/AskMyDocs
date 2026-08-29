<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Services\Kb\Provenance\ProvenanceToolFirewall;
use App\Support\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Padosoft\AskMyDocsConnectorBase\ProvenanceTier;

/**
 * How much of the corpus was written outside the organisation.
 *
 * ADR 0028 phase 1 ships labels and no enforcement, on the reasoning that a
 * label nobody can see is not worth storing. This is the seeing part, and the
 * single core the artisan command, the HTTP endpoint and the MCP tool all
 * delegate to (R44) — the surfaces adapt input and output, the counting
 * happens once, here.
 *
 * Every query is scoped to the active tenant (R30) and skips soft-deleted
 * rows (R2): a corpus figure that counts another customer's documents, or
 * documents an operator has already deleted, is worse than no figure.
 */
final class ProvenanceInsightsService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ProvenanceToolFirewall $firewall,
    ) {}

    /**
     * What the corpus composition MEANS for this deployment (v8.34).
     *
     * Reported alongside the counts on all three surfaces, because the
     * externally-authored count only tells an operator something once they
     * know whether it changes behaviour. "41 documents were written outside
     * the organisation" reads very differently depending on whether those
     * documents can drive a tool call.
     *
     * @return array<string, bool>
     */
    public function policy(): array
    {
        return [
            'tool_firewall_enabled' => $this->firewall->enabled(),
        ];
    }

    /**
     * Corpus composition by provenance tier.
     *
     * `null` in the column means no connector declared anything, which is
     * every document written before the capability existed and everything
     * ingested through the CLI walker or the HTTP batch endpoint. It is
     * resolved through `ProvenanceTier::fromStorage()` rather than reported
     * as its own bucket, so the totals describe the corpus as retrieval sees
     * it — an undeclared document is treated as internally authored, and
     * saying so out loud is the point.
     *
     * `declared` is reported alongside precisely so that resolution is not
     * mistaken for evidence: a corpus reading 100% trusted-internal with 0
     * declared has not been assessed at all.
     *
     * @return array{
     *     project_key: string|null,
     *     total: int,
     *     declared: int,
     *     undeclared: int,
     *     externally_authored: int,
     *     externally_authored_pct: float,
     *     tiers: list<array{tier: string, label: string, count: int, pct: float, externally_authored: bool}>
     * }
     */
    public function summary(?string $projectKey = null): array
    {
        $rows = $this->baseQuery($projectKey)
            ->select('provenance_tier', DB::raw('count(*) as aggregate'))
            ->groupBy('provenance_tier')
            ->pluck('aggregate', 'provenance_tier')
            ->all();

        $total = 0;
        $declared = 0;
        $counts = [];

        foreach (ProvenanceTier::cases() as $tier) {
            $counts[$tier->value] = 0;
        }

        foreach ($rows as $stored => $count) {
            $count = (int) $count;
            $total += $count;

            // A grouped key comes back as the string '' for NULL on some
            // drivers; normalise before deciding whether it was declared.
            $raw = ($stored === null || $stored === '') ? null : (string) $stored;

            if ($raw !== null) {
                $declared += $count;
            }

            $counts[ProvenanceTier::fromStorage($raw)->value] += $count;
        }

        $tiers = [];
        $externallyAuthored = 0;

        foreach (ProvenanceTier::cases() as $tier) {
            $count = $counts[$tier->value];

            if ($tier->isExternallyAuthored()) {
                $externallyAuthored += $count;
            }

            $tiers[] = [
                'tier' => $tier->value,
                'label' => $tier->label(),
                'count' => $count,
                'pct' => $this->percentage($count, $total),
                'externally_authored' => $tier->isExternallyAuthored(),
            ];
        }

        return [
            'project_key' => $projectKey,
            'total' => $total,
            'declared' => $declared,
            'undeclared' => $total - $declared,
            'externally_authored' => $externallyAuthored,
            'externally_authored_pct' => $this->percentage($externallyAuthored, $total),
            'tiers' => $tiers,
        ];
    }

    /**
     * The same composition, broken down per project.
     *
     * A tenant-wide percentage hides the shape that matters: one project fed
     * by a mailbox and nine fed by internal wikis averages to something
     * reassuring while the first is entirely externally authored.
     *
     * @return list<array{project_key: string, total: int, externally_authored: int, externally_authored_pct: float}>
     */
    public function byProject(int $limit = 50): array
    {
        // "Externally authored" has to mean the same thing here as in
        // summary(), which resolves every stored value through
        // ProvenanceTier::fromStorage() -- and that maps an UNRECOGNISED
        // value to UntrustedExternal rather than to the trusted default.
        // Matching only the literal 'untrusted-external' would count a tier
        // written by a newer version as external in the headline and as
        // internal here, so the breakdown would contradict the number above
        // it and mis-order the projects on exactly the rows that deserve
        // attention.
        //
        // So the predicate is the inverse: anything that is neither absent
        // nor a recognised non-external tier. Derived from the enum, so a
        // tier added later is covered without touching this query.
        $internalTiers = array_values(array_map(
            static fn (ProvenanceTier $tier): string => $tier->value,
            array_filter(
                ProvenanceTier::cases(),
                static fn (ProvenanceTier $tier): bool => ! $tier->isExternallyAuthored(),
            ),
        ));

        $placeholders = implode(', ', array_fill(0, count($internalTiers), '?'));

        $rows = $this->baseQuery(null)
            ->select(
                'project_key',
                DB::raw('count(*) as total'),
                DB::raw(
                    'sum(case when provenance_tier is not null'
                    ." and provenance_tier not in ({$placeholders})"
                    .' then 1 else 0 end) as external_total'
                ),
            )
            ->addBinding($internalTiers, 'select')
            ->groupBy('project_key')
            ->orderByDesc('external_total')
            ->orderBy('project_key')
            ->limit(max(1, min(200, $limit)))
            ->get();

        return $rows->map(function ($row): array {
            $total = (int) $row->total;
            $external = (int) $row->external_total;

            return [
                'project_key' => (string) $row->project_key,
                'total' => $total,
                'externally_authored' => $external,
                'externally_authored_pct' => $this->percentage($external, $total),
            ];
        })->all();
    }

    private function baseQuery(?string $projectKey): Builder
    {
        $query = DB::table('knowledge_documents')
            ->where('tenant_id', $this->tenant->current())
            ->whereNull('deleted_at');

        if ($projectKey !== null && $projectKey !== '') {
            $query->where('project_key', $projectKey);
        }

        return $query;
    }

    private function percentage(int $part, int $total): float
    {
        if ($total <= 0) {
            return 0.0;
        }

        return round(($part / $total) * 100, 2);
    }
}

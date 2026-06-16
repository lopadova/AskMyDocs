<?php

declare(strict_types=1);

namespace App\Services\Digest;

use App\Models\KbCanonicalHealthSnapshot;
use App\Models\KbContributionEvent;
use App\Models\KbSearchFailure;
use App\Models\KnowledgeDocument;
use App\Services\Engagement\EngagementMetricsService;
use App\Support\TenantContext;
use Illuminate\Support\Carbon;

/**
 * v8.15/W2 — assembles a {@see DigestPayload} for the active tenant.
 *
 * Pure read/compose: every query is tenant-scoped (R30) and pushed into SQL
 * (R3). It reuses {@see EngagementMetricsService} for the headline metrics +
 * leaderboard and joins the contribution log / health snapshots / content-gap
 * rollups for the section lists — no signal is recomputed that an existing
 * service already owns.
 */
final class DigestComposer
{
    private const SECTION_LIMIT = 8;

    public function __construct(
        private readonly EngagementMetricsService $metrics,
        private readonly TenantContext $tenants,
    ) {
    }

    public function composeForTenant(string $frequency = 'weekly'): DigestPayload
    {
        $windowDays = $frequency === 'monthly' ? 30 : 7;
        $since = Carbon::now()->subDays($windowDays);
        $tenant = $this->tenants->current();

        return new DigestPayload(
            tenantId: $tenant,
            frequency: $frequency,
            periodStart: $since->toDateString(),
            periodEnd: Carbon::now()->toDateString(),
            metrics: $this->metrics->snapshotMetrics($windowDays),
            newDocs: $this->newDocs($since),
            staleDocs: $this->staleDocs(),
            topGaps: $this->topGaps(),
            leaderboard: $this->metrics->leaderboard($windowDays, 5),
        );
    }

    /**
     * Newly created / promoted documents in the window, resolved to titles.
     *
     * @return list<array{title:string, project_key:string, slug:?string, change:string}>
     */
    private function newDocs(Carbon $since): array
    {
        $events = KbContributionEvent::query()
            ->forTenant($this->tenants->current())
            ->whereIn('event', [KbContributionEvent::EVENT_CREATED, KbContributionEvent::EVENT_PROMOTED])
            ->where('created_at', '>=', $since)
            ->whereNotNull('document_id')
            ->orderByDesc('created_at')
            ->limit(self::SECTION_LIMIT * 3)
            ->get(['document_id', 'event', 'project_key']);

        if ($events->isEmpty()) {
            return [];
        }

        $docs = KnowledgeDocument::query()
            ->forTenant($this->tenants->current())
            ->whereIn('id', $events->pluck('document_id')->unique()->all())
            ->get(['id', 'title', 'project_key', 'slug'])
            ->keyBy('id');

        $rows = [];
        foreach ($events as $event) {
            $doc = $docs->get($event->document_id);
            if ($doc === null) {
                continue;
            }
            $rows[] = [
                'title' => (string) ($doc->title ?? $doc->slug ?? 'Untitled'),
                'project_key' => (string) $event->project_key,
                'slug' => $doc->slug,
                'change' => (string) $event->event,
            ];
            if (count($rows) >= self::SECTION_LIMIT) {
                break;
            }
        }

        return $rows;
    }

    /**
     * Documents most in need of review (highest decision-debt score).
     *
     * @return list<array{title:string, project_key:string, slug:?string, age_days:int, debt_score:int}>
     */
    private function staleDocs(): array
    {
        $threshold = (int) config('askmydocs.kb_health.threshold_event_score', 70);

        $snapshots = KbCanonicalHealthSnapshot::query()
            ->forTenant($this->tenants->current())
            ->where('health_score', '>=', $threshold)
            ->orderByDesc('health_score')
            ->limit(self::SECTION_LIMIT)
            ->get(['knowledge_document_id', 'project_key', 'doc_slug', 'health_score', 'computed_at']);

        if ($snapshots->isEmpty()) {
            return [];
        }

        $docs = KnowledgeDocument::query()
            ->forTenant($this->tenants->current())
            ->whereIn('id', $snapshots->pluck('knowledge_document_id')->all())
            ->get(['id', 'title', 'indexed_at', 'created_at'])
            ->keyBy('id');

        $rows = [];
        foreach ($snapshots as $snap) {
            $doc = $docs->get($snap->knowledge_document_id);
            $touched = $doc?->indexed_at ?? $doc?->created_at;
            $rows[] = [
                'title' => (string) ($doc?->title ?? $snap->doc_slug ?? 'Untitled'),
                'project_key' => (string) $snap->project_key,
                'slug' => $snap->doc_slug,
                'age_days' => $touched !== null ? (int) Carbon::parse($touched)->diffInDays(Carbon::now()) : 0,
                'debt_score' => (int) $snap->health_score,
            ];
        }

        return $rows;
    }

    /**
     * Top unanswered questions (open content gaps) by frequency.
     *
     * @return list<array{question:string, occurrences:int, project_key:string}>
     */
    private function topGaps(): array
    {
        return KbSearchFailure::query()
            ->forTenant($this->tenants->current())
            ->whereNull('resolved_at')
            ->orderByDesc('occurrences')
            ->orderByDesc('last_seen_at')
            ->limit(self::SECTION_LIMIT)
            ->get(['normalized_query', 'occurrences', 'project_key'])
            ->map(static fn ($g): array => [
                'question' => (string) $g->normalized_query,
                'occurrences' => (int) $g->occurrences,
                'project_key' => (string) $g->project_key,
            ])
            ->all();
    }
}

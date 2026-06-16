<?php

declare(strict_types=1);

namespace App\Services\Engagement;

use App\Models\ChatLog;
use App\Models\KbCanonicalHealthSnapshot;
use App\Models\KbContributionEvent;
use App\Models\KbEngagementSnapshot;
use App\Models\KbSearchFailure;
use App\Models\KnowledgeDocument;
use App\Models\User;
use App\Scopes\AccessScopeScope;
use App\Support\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * v8.15/W1 — engagement analytics over the existing KB signals.
 *
 * Pure read service: every aggregation is pushed into SQL (R3) and scoped to the
 * active tenant (R30) via {@see TenantContext}. Consumed by
 * {@see \App\Console\Commands\EngagementComputeCommand} (daily snapshot), the
 * admin engagement API, the MCP summary tool, and (W2+) the digest composer.
 *
 * It computes NO new signal it could read from an existing service — it joins
 * the contribution-event log with documents, chat logs, health snapshots, and
 * content-gap rollups.
 */
class EngagementMetricsService
{
    public function __construct(private readonly TenantContext $tenants)
    {
    }

    /**
     * The full metrics blob persisted into a daily engagement snapshot.
     *
     * @return array<string, mixed>
     */
    public function snapshotMetrics(int $windowDays = 7): array
    {
        $since = Carbon::now()->subDays(max(1, $windowDays));

        $byEvent = $this->contributionCountsByEvent($since);
        $answers = $this->answeredQuestions($since);
        // Window the gaps consistently with `answers` so `answer_rate` divides
        // recent answers by recent (answers + gaps) — not by lifetime gaps.
        $windowGaps = $this->contentGapsInWindow($since);
        $total = $answers + $windowGaps;

        return [
            'window_days' => $windowDays,
            'contributors' => $this->distinctContributors($since),
            'new_docs' => $byEvent[KbContributionEvent::EVENT_CREATED] ?? 0,
            'modified_docs' => $byEvent[KbContributionEvent::EVENT_MODIFIED] ?? 0,
            'promoted_docs' => $byEvent[KbContributionEvent::EVENT_PROMOTED] ?? 0,
            'reviewed_docs' => $byEvent[KbContributionEvent::EVENT_REVIEWED] ?? 0,
            'answers' => $answers,
            'open_gaps' => $this->openContentGaps(),
            'window_gaps' => $windowGaps,
            'answer_rate' => $total > 0 ? round($answers / $total, 4) : null,
            'canonical_coverage_pct' => $this->canonicalCoveragePct(),
            // NB: higher debt score = staler / more decision-debt (see KbHealthService),
            // i.e. higher is WORSE, not healthier. Named to avoid dashboard confusion.
            'avg_debt_score' => $this->averageDebtScore(),
            'stale_count' => $this->staleCount(),
            'top_contributors' => $this->leaderboard($windowDays, 10),
        ];
    }

    /**
     * Top contributors by summed contribution weight in the window.
     *
     * @return list<array{user_id:int, name:string, score:int, events:int}>
     */
    public function leaderboard(int $windowDays = 7, int $limit = 10): array
    {
        $since = Carbon::now()->subDays(max(1, $windowDays));

        $rows = KbContributionEvent::query()
            ->forTenant($this->tenants->current())
            ->where('created_at', '>=', $since)
            ->whereNotNull('user_id')
            ->selectRaw('user_id, SUM(weight) as score, COUNT(*) as events')
            ->groupBy('user_id')
            ->orderByDesc('score')
            ->limit(max(1, $limit))
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $names = User::query()
            ->whereIn('id', $rows->pluck('user_id')->all())
            ->pluck('name', 'id');

        return $rows->map(static fn ($r): array => [
            'user_id' => (int) $r->user_id,
            'name' => (string) ($names[$r->user_id] ?? 'Unknown'),
            'score' => (int) $r->score,
            'events' => (int) $r->events,
        ])->all();
    }

    /**
     * Per-contributor stats for the user dashboard (W4) and "your impact".
     *
     * @return array{score:int, events:int, by_event:array<string,int>, citations:int}
     */
    public function contributorStats(int $userId, int $windowDays = 30): array
    {
        $since = Carbon::now()->subDays(max(1, $windowDays));
        $tenant = $this->tenants->current();

        $byEvent = KbContributionEvent::query()
            ->forTenant($tenant)
            ->where('user_id', $userId)
            ->where('created_at', '>=', $since)
            ->selectRaw('event, SUM(weight) as score, COUNT(*) as events')
            ->groupBy('event')
            ->get();

        return [
            'score' => (int) $byEvent->sum('score'),
            'events' => (int) $byEvent->sum('events'),
            'by_event' => $byEvent->mapWithKeys(static fn ($r): array => [(string) $r->event => (int) $r->events])->all(),
            'citations' => (int) $byEvent->firstWhere('event', KbContributionEvent::EVENT_CITED)?->events,
        ];
    }

    /**
     * The "your KB" personal dashboard for one user (W4): contributions, rank,
     * authored docs, questions asked, active days, and the user's own docs that
     * now need review. Tenant-scoped (R30).
     *
     * @return array<string, mixed>
     */
    public function userDashboard(int $userId, int $windowDays = 30): array
    {
        $since = Carbon::now()->subDays(max(1, $windowDays));

        return [
            'window_days' => $windowDays,
            'contributions' => $this->contributorStats($userId, $windowDays),
            'rank' => $this->contributorRank($userId, $windowDays),
            'authored_docs' => count($this->authoredDocumentIds($userId)),
            'questions_asked' => $this->questionsAskedBy($userId, $since),
            'active_days' => $this->activeContributionDays($userId, $since),
            'docs_needing_review' => $this->myDocsNeedingReview($userId),
        ];
    }

    /**
     * Time-series of recent engagement snapshots for the admin trend charts.
     *
     * @return list<array{date:string, contributors:int, new_docs:int, answers:int, avg_debt_score:?float}>
     */
    public function trendSeries(int $points = 8): array
    {
        return KbEngagementSnapshot::query()
            ->forTenant($this->tenants->current())
            ->orderByDesc('snapshot_date')
            ->limit(max(1, $points))
            ->get(['snapshot_date', 'metrics'])
            ->reverse()
            ->map(static function ($s): array {
                $m = $s->metrics ?? [];

                return [
                    'date' => $s->snapshot_date->toDateString(),
                    'contributors' => (int) ($m['contributors'] ?? 0),
                    'new_docs' => (int) ($m['new_docs'] ?? 0),
                    'answers' => (int) ($m['answers'] ?? 0),
                    'avg_debt_score' => isset($m['avg_debt_score']) ? (float) $m['avg_debt_score'] : null,
                ];
            })
            ->values()
            ->all();
    }

    /** Leaderboard position (1-based) of the user in the window, or null if no activity. */
    private function contributorRank(int $userId, int $windowDays): int|null
    {
        $since = Carbon::now()->subDays(max(1, $windowDays));
        $tenant = $this->tenants->current();

        $myScore = (int) KbContributionEvent::query()
            ->forTenant($tenant)
            ->where('user_id', $userId)
            ->where('created_at', '>=', $since)
            ->sum('weight');

        if ($myScore === 0) {
            return null;
        }

        // Number of distinct users whose summed weight strictly exceeds mine.
        $ahead = KbContributionEvent::query()
            ->forTenant($tenant)
            ->where('created_at', '>=', $since)
            ->whereNotNull('user_id')
            ->selectRaw('user_id, SUM(weight) as score')
            ->groupBy('user_id')
            ->havingRaw('SUM(weight) > ?', [$myScore])
            ->get()
            ->count();

        return $ahead + 1;
    }

    /**
     * @return list<int>
     */
    private function authoredDocumentIds(int $userId): array
    {
        return KbContributionEvent::query()
            ->forTenant($this->tenants->current())
            ->where('user_id', $userId)
            ->whereIn('event', [KbContributionEvent::EVENT_CREATED, KbContributionEvent::EVENT_PROMOTED])
            ->whereNotNull('document_id')
            ->distinct()
            ->pluck('document_id')
            ->map(static fn ($v): int => (int) $v)
            ->all();
    }

    private function questionsAskedBy(int $userId, Carbon $since): int
    {
        return (int) ChatLog::query()
            ->forTenant($this->tenants->current())
            ->where('user_id', $userId)
            ->where('created_at', '>=', $since)
            ->count();
    }

    private function activeContributionDays(int $userId, Carbon $since): int
    {
        // Count distinct contribution days in SQL (R3 — never materialise a
        // heavy contributor's full event set). Driver-portable date bucket,
        // mirroring AdminMetricsService.
        $dateExpr = DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m-%d', created_at)"
            : 'DATE(created_at)';

        return (int) KbContributionEvent::query()
            ->forTenant($this->tenants->current())
            ->where('user_id', $userId)
            ->where('created_at', '>=', $since)
            ->distinct()
            ->count(DB::raw($dateExpr));
    }

    /**
     * The user's own authored docs whose decision-debt score now crosses the
     * review threshold (their personal "needs review" queue).
     *
     * @return list<array{title:string, debt_score:int, slug:?string}>
     */
    private function myDocsNeedingReview(int $userId): array
    {
        $docIds = $this->authoredDocumentIds($userId);
        if ($docIds === []) {
            return [];
        }

        $threshold = (int) config('askmydocs.kb_health.threshold_event_score', 70);

        $snapshots = KbCanonicalHealthSnapshot::query()
            ->forTenant($this->tenants->current())
            ->whereIn('knowledge_document_id', $docIds)
            ->where('health_score', '>=', $threshold)
            ->orderByDesc('health_score')
            ->limit(10)
            ->get(['knowledge_document_id', 'doc_slug', 'health_score']);

        if ($snapshots->isEmpty()) {
            return [];
        }

        // Bypass AccessScopeScope: this is a SYSTEM-side title enrichment for
        // docs the user provably authored (their own contribution events), so
        // it must not be filtered by the caller's project-membership read scope.
        $titles = KnowledgeDocument::query()
            ->withoutGlobalScope(AccessScopeScope::class)
            ->forTenant($this->tenants->current())
            ->whereIn('id', $snapshots->pluck('knowledge_document_id')->all())
            ->pluck('title', 'id');

        return $snapshots->map(static fn ($s): array => [
            'title' => (string) ($titles[$s->knowledge_document_id] ?? $s->doc_slug ?? 'Untitled'),
            'slug' => $s->doc_slug,
            'debt_score' => (int) $s->health_score,
        ])->all();
    }

    private function distinctContributors(Carbon $since): int
    {
        return (int) KbContributionEvent::query()
            ->forTenant($this->tenants->current())
            ->where('created_at', '>=', $since)
            ->whereNotNull('user_id')
            ->distinct()
            ->count('user_id');
    }

    /**
     * @return array<string, int>
     */
    private function contributionCountsByEvent(Carbon $since): array
    {
        return KbContributionEvent::query()
            ->forTenant($this->tenants->current())
            ->where('created_at', '>=', $since)
            ->selectRaw('event, COUNT(*) as c')
            ->groupBy('event')
            ->pluck('c', 'event')
            ->map(static fn ($v): int => (int) $v)
            ->all();
    }

    private function answeredQuestions(Carbon $since): int
    {
        return (int) ChatLog::query()
            ->forTenant($this->tenants->current())
            ->where('created_at', '>=', $since)
            ->count();
    }

    private function openContentGaps(): int
    {
        return (int) KbSearchFailure::query()
            ->forTenant($this->tenants->current())
            ->whereNull('resolved_at')
            ->count();
    }

    private function contentGapsInWindow(Carbon $since): int
    {
        return (int) KbSearchFailure::query()
            ->forTenant($this->tenants->current())
            ->whereNull('resolved_at')
            ->where('last_seen_at', '>=', $since)
            ->count();
    }

    private function canonicalCoveragePct(): float
    {
        $tenant = $this->tenants->current();

        $total = (int) KnowledgeDocument::query()->forTenant($tenant)->count();
        if ($total === 0) {
            return 0.0;
        }

        $canonical = (int) KnowledgeDocument::query()
            ->forTenant($tenant)
            ->where('is_canonical', true)
            ->count();

        return round(($canonical / $total) * 100, 2);
    }

    private function averageDebtScore(): ?float
    {
        $avg = KbCanonicalHealthSnapshot::query()
            ->forTenant($this->tenants->current())
            ->avg('health_score');

        return $avg === null ? null : round((float) $avg, 1);
    }

    private function staleCount(): int
    {
        $threshold = (int) config('askmydocs.kb_health.threshold_event_score', 70);

        return (int) KbCanonicalHealthSnapshot::query()
            ->forTenant($this->tenants->current())
            ->where('health_score', '>=', $threshold)
            ->count();
    }
}

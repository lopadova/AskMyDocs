<?php

declare(strict_types=1);

namespace App\Services\Engagement;

use App\Models\KbContributionEvent;
use App\Models\KbGamificationInsight;
use App\Models\KnowledgeDocument;
use App\Support\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * v8.18/W4 — the SINGLE core (R44) behind the AI gamification insights capability:
 * compute curation-quality metrics → narrate them → persist one snapshot per
 * (tenant, scope, period). The Artisan command, the HTTP endpoints, and the MCP
 * tool are all thin layers over this service — no logic is duplicated.
 *
 * Gated by `kb.gamification.enabled` (R43): when off, {@see recomputeForTenant()}
 * is a no-op and the read methods return null, so the feature degrades cleanly.
 * Tenant-scoped throughout (R30).
 */
final class GamificationInsightsService
{
    /** Cap on per-tenant contributor coaching cards generated per run. */
    private const MAX_USER_CARDS = 25;

    public function __construct(
        private readonly GamificationQualityMetricsService $metrics,
        private readonly GamificationNarratorService $narrator,
        private readonly TenantContext $tenants,
    ) {
    }

    public function enabled(): bool
    {
        return (bool) config('kb.gamification.enabled', true);
    }

    /**
     * Recompute + persist insights for the active tenant across all three scopes
     * for the given period (default: current ISO week). Idempotent — upserts on
     * (tenant, scope, scope_id, period). Returns per-scope counts.
     *
     * @return array{period:string, users:int, projects:int, tenant:int}
     */
    public function recomputeForTenant(?string $period = null): array
    {
        if (! $this->enabled()) {
            return ['period' => $period ?? $this->currentPeriod(), 'users' => 0, 'projects' => 0, 'tenant' => 0];
        }

        $period = $period ?? $this->currentPeriod();
        $users = 0;
        $projects = 0;

        foreach ($this->topContributorIds() as $userId) {
            $metrics = $this->metrics->userQuality($userId);
            $narrated = $this->narrator->narrateUser($userId, $metrics);
            $this->persist(KbGamificationInsight::SCOPE_USER, (string) $userId, $period, $metrics, $narrated);
            $users++;
        }

        foreach ($this->projectKeys() as $projectKey) {
            $metrics = $this->metrics->projectQuality($projectKey);
            $narrated = $this->narrator->narrateProject($projectKey, $metrics);
            $this->persist(KbGamificationInsight::SCOPE_PROJECT, $projectKey, $period, $metrics, $narrated);
            $projects++;
        }

        $tenantMetrics = $this->metrics->tenantQuality();
        $tenantNarrated = $this->narrator->narrateTenant($tenantMetrics);
        $this->persist(KbGamificationInsight::SCOPE_TENANT, '', $period, $tenantMetrics, $tenantNarrated);

        return ['period' => $period, 'users' => $users, 'projects' => $projects, 'tenant' => 1];
    }

    /**
     * The caller's own coaching card (latest period, or a specific one). Null when
     * the feature is disabled or nothing has been computed yet.
     *
     * @return array<string, mixed>|null
     */
    public function forUser(int $userId, ?string $period = null): ?array
    {
        return $this->read(KbGamificationInsight::SCOPE_USER, (string) $userId, $period);
    }

    /**
     * A project or tenant insight (admin surface). For the tenant scope pass
     * scopeId ''. Null when disabled or not yet computed.
     *
     * @return array<string, mixed>|null
     */
    public function forScope(string $scopeType, string $scopeId = '', ?string $period = null): ?array
    {
        return $this->read($scopeType, $scopeId, $period);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function read(string $scopeType, string $scopeId, ?string $period): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        $query = KbGamificationInsight::query()
            ->forTenant($this->tenants->current())
            ->forScope($scopeType, $scopeId);

        $row = $period !== null
            ? $query->where('period_label', $period)->first()
            : $query->latestInsight()->first();

        if ($row === null) {
            return null;
        }

        return [
            'scope_type' => $row->scope_type,
            'scope_id' => $row->scope_id,
            'period_label' => $row->period_label,
            'metrics' => $row->metrics ?? [],
            'narrative' => $row->narrative ?? [],
            'titles' => $row->titles ?? [],
            'model' => $row->model,
            'computed_at' => $row->computed_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $metrics
     * @param  array{narrative:array<string,mixed>, titles:list<array<string,mixed>>, model:?string}  $narrated
     */
    private function persist(string $scopeType, string $scopeId, string $period, array $metrics, array $narrated): void
    {
        $start = microtime(true);

        // ATOMIC upsert on the composite UNIQUE (tenant_id, scope_type, scope_id,
        // period_label) — NOT updateOrCreate, whose SELECT-then-write races under
        // concurrency (double-clicked Rigenera, retries, a manual run overlapping
        // the weekly cron) and would throw a unique-constraint violation (R21).
        KbGamificationInsight::query()->upsert(
            [[
                'tenant_id' => $this->tenants->current(),
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
                'period_label' => $period,
                'metrics' => json_encode($metrics, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'narrative' => json_encode($narrated['narrative'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'titles' => json_encode($narrated['titles'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'model' => $narrated['model'],
                'computed_at' => Carbon::now(),
                'computed_duration_ms' => (int) round((microtime(true) - $start) * 1000),
            ]],
            ['tenant_id', 'scope_type', 'scope_id', 'period_label'],
            ['metrics', 'narrative', 'titles', 'model', 'computed_at', 'computed_duration_ms'],
        );
    }

    /**
     * @return list<int>
     */
    private function topContributorIds(): array
    {
        return KbContributionEvent::query()
            ->forTenant($this->tenants->current())
            ->whereNotNull('user_id')
            ->select('user_id')
            ->groupBy('user_id')
            ->orderByRaw('SUM(weight) DESC')
            ->limit(self::MAX_USER_CARDS)
            ->pluck('user_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * The busiest projects (by doc count) to write per-project insight rows for.
     * Capped at {@see GamificationQualityMetricsService::MAX_TENANT_PROJECTS} so a
     * tenant with thousands of projects can't fan out thousands of LLM calls in a
     * single narrate run; the cap is LOGGED (no silent truncation).
     *
     * @return list<string>
     */
    private function projectKeys(): array
    {
        $tenant = $this->tenants->current();
        $cap = GamificationQualityMetricsService::MAX_TENANT_PROJECTS;

        $total = (int) KnowledgeDocument::query()
            ->forTenant($tenant)
            ->whereNotNull('project_key')
            ->distinct()
            ->count('project_key');

        if ($total > $cap) {
            Log::info('GamificationInsightsService: capping per-project insights.', [
                'tenant_id' => $tenant,
                'total_projects' => $total,
                'computed' => $cap,
            ]);
        }

        return KnowledgeDocument::query()
            ->forTenant($tenant)
            ->whereNotNull('project_key')
            ->select('project_key', DB::raw('COUNT(*) as doc_count'))
            ->groupBy('project_key')
            ->orderByDesc('doc_count')
            ->orderBy('project_key')
            ->limit($cap)
            ->pluck('project_key')
            ->map(static fn ($k): string => (string) $k)
            ->all();
    }

    private function currentPeriod(): string
    {
        // ISO year + ISO week, e.g. "2026-W25".
        return Carbon::now()->format('o-\WW');
    }
}

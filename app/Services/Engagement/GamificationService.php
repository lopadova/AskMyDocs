<?php

declare(strict_types=1);

namespace App\Services\Engagement;

use App\Models\KbContributionEvent;
use App\Models\KbUserBadge;
use App\Support\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * v8.15/W5 — the opt-in gamification layer: award badges when a user's all-time
 * engagement crosses a config-defined threshold, and expose the badge catalog
 * (earned + progress) for the "My KB" dashboard.
 *
 * Gated by `kb.gamification.enabled` (default-off, R43): when disabled, nothing
 * is awarded and {@see badgesFor()} reports `enabled: false` with no badges.
 * Tenant-scoped (R30); all-time metrics are aggregated in SQL (R3).
 */
final class GamificationService
{
    public function __construct(private readonly TenantContext $tenants)
    {
    }

    public function enabled(): bool
    {
        return (bool) config('kb.gamification.enabled', false);
    }

    /**
     * Award any newly-earned badges for the user. Returns the keys awarded this
     * run (empty when disabled or nothing new crossed). Idempotent — the unique
     * (tenant,user,badge) constraint + insertOrIgnore prevents duplicates.
     *
     * @return list<string>
     */
    public function evaluate(int $userId): array
    {
        if (! $this->enabled()) {
            return [];
        }

        $metrics = $this->userMetrics($userId);
        $tenant = $this->tenants->current();

        $already = KbUserBadge::query()
            ->forTenant($tenant)
            ->where('user_id', $userId)
            ->pluck('badge_key')
            ->all();

        $awarded = [];
        foreach ($this->catalog() as $badge) {
            if (in_array($badge['key'], $already, true)) {
                continue;
            }
            if (($metrics[$badge['metric']] ?? 0) >= $badge['threshold']) {
                $awarded[] = $badge['key'];
            }
        }

        if ($awarded !== []) {
            KbUserBadge::query()->insertOrIgnore(array_map(static fn (string $key): array => [
                'tenant_id' => $tenant,
                'user_id' => $userId,
                'badge_key' => $key,
                'awarded_at' => Carbon::now(),
            ], $awarded));
        }

        return $awarded;
    }

    /**
     * The badge catalog for a user: each badge with earned flag + progress.
     * When gamification is disabled, returns enabled=false and an empty list so
     * the FE hides the section entirely.
     *
     * @return array{enabled:bool, badges:list<array<string,mixed>>}
     */
    public function badgesFor(int $userId): array
    {
        if (! $this->enabled()) {
            return ['enabled' => false, 'badges' => []];
        }

        $metrics = $this->userMetrics($userId);
        $earned = KbUserBadge::query()
            ->forTenant($this->tenants->current())
            ->where('user_id', $userId)
            ->pluck('awarded_at', 'badge_key');

        $badges = [];
        foreach ($this->catalog() as $badge) {
            $value = (int) ($metrics[$badge['metric']] ?? 0);
            $isEarned = $earned->has($badge['key']) || $value >= $badge['threshold'];
            $badges[] = [
                'key' => $badge['key'],
                'label' => is_string($badge['label'] ?? null) ? $badge['label'] : $badge['key'],
                'icon' => is_string($badge['icon'] ?? null) ? $badge['icon'] : '🏅',
                'metric' => $badge['metric'],
                'threshold' => $badge['threshold'],
                'progress' => min($value, $badge['threshold']),
                'earned' => $isEarned,
                'awarded_at' => $earned->get($badge['key']),
            ];
        }

        return ['enabled' => true, 'badges' => $badges];
    }

    /**
     * All-time engagement metrics for badge evaluation (SQL-aggregated, R3).
     *
     * @return array{score:int, events:int, authored:int, active_days:int}
     */
    private function userMetrics(int $userId): array
    {
        $tenant = $this->tenants->current();

        $base = KbContributionEvent::query()
            ->forTenant($tenant)
            ->where('user_id', $userId);

        $dateExpr = DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m-%d', created_at)"
            : 'DATE(created_at)';

        return [
            'score' => (int) (clone $base)->sum('weight'),
            'events' => (int) (clone $base)->count(),
            'authored' => (int) (clone $base)
                ->whereIn('event', [KbContributionEvent::EVENT_CREATED, KbContributionEvent::EVENT_PROMOTED])
                ->whereNotNull('document_id')
                ->distinct()
                ->count('document_id'),
            'active_days' => (int) (clone $base)->distinct()->count(DB::raw($dateExpr)),
        ];
    }

    /**
     * The operator-tunable badge catalog, defensively filtered: a malformed
     * entry (missing/non-scalar key/metric/threshold) is dropped rather than
     * crashing the awarding loop or the dashboard. `label` defaults to the key.
     *
     * @return list<array{key:string, label?:string, icon?:string, metric:string, threshold:int}>
     */
    private function catalog(): array
    {
        $catalog = config('kb.gamification.badges', []);
        if (! is_array($catalog)) {
            return [];
        }

        $valid = array_filter($catalog, static fn ($b): bool => is_array($b)
            && isset($b['key'], $b['metric'], $b['threshold'])
            && is_string($b['key']) && $b['key'] !== ''
            && is_string($b['metric'])
            && is_numeric($b['threshold']));

        return array_values(array_map(static function (array $b): array {
            $b['threshold'] = (int) $b['threshold'];

            return $b;
        }, $valid));
    }
}

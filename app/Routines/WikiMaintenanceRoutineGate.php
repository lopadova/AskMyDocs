<?php

declare(strict_types=1);

namespace App\Routines;

use Illuminate\Support\Facades\Log;
use Padosoft\Routines\Contracts\Routine\RoutineStatus;
use Padosoft\Routines\Contracts\Target\RoutineTarget;
use Padosoft\Routines\Models\Routine;
use Padosoft\Routines\Targets\TargetRegistry;

/**
 * v8.39/W5 (ADR 0033 §6) — decides whether the legacy `kb_wiki_maintain`
 * Tier-1 scheduler entry should keep running, or whether the routine now
 * owns firing wiki maintenance (via `padosoft/laravel-routines`' own
 * `routines:tick`, which this app never re-implements).
 *
 * Returns `true` (keep the cron — the SAFE default) unless ALL of:
 *   1. `KB_WIKI_ROUTINE_ENABLED=true`
 *   2. The adapter is registered ({@see App\Providers\AppServiceProvider::registerWikiRoutineTarget()})
 *   3. An ACTIVE `routines` row exists for {@see WikiMaintenanceRoutineTarget::TYPE}
 *
 * Condition 3 is a database read, unlike every other Tier-1 scheduler gate
 * in this app (all of which only read `config()`) — this class exists
 * specifically to wrap that one DB-dependent condition in a `try/catch` so
 * an unreachable database or a not-yet-migrated `routines` table degrades
 * to "keep the cron, log why" rather than losing the nightly run entirely.
 * `withSchedule()`'s closure runs on every console boot, not only
 * `schedule:run`, so this check must never be allowed to throw.
 */
final class WikiMaintenanceRoutineGate
{
    public function cronSlotShouldStayActive(): bool
    {
        if (! (bool) config('kb.wiki_routine.enabled', false)) {
            return true;
        }

        if (! interface_exists(RoutineTarget::class) || ! class_exists(TargetRegistry::class)) {
            Log::warning('kb_wiki_maintain: KB_WIKI_ROUTINE_ENABLED is true but laravel-routines contracts/engine are unavailable — keeping the legacy cron.');

            return true;
        }

        if (! app(TargetRegistry::class)->has(WikiMaintenanceRoutineTarget::TYPE)) {
            Log::warning('kb_wiki_maintain: KB_WIKI_ROUTINE_ENABLED is true but the target is not registered — keeping the legacy cron.');

            return true;
        }

        try {
            $hasActiveRoutine = Routine::query()
                ->where('target_type', WikiMaintenanceRoutineTarget::TYPE)
                ->where('status', RoutineStatus::Active->value)
                ->exists();
        } catch (\Throwable $e) {
            Log::warning('kb_wiki_maintain: could not check for an active wiki-maintenance routine — keeping the legacy cron.', [
                'exception' => $e->getMessage(),
            ]);

            return true;
        }

        if (! $hasActiveRoutine) {
            Log::info('kb_wiki_maintain: KB_WIKI_ROUTINE_ENABLED is true and the target is registered, but no active routine exists yet — keeping the legacy cron.');

            return true;
        }

        return false;
    }
}

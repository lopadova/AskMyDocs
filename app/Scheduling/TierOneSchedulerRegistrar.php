<?php

declare(strict_types=1);

namespace App\Scheduling;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

/**
 * v8.0/W2.4 — Tier-1 scheduler registrar.
 *
 * Reads each host-side slot's cron + enabled flag from
 * `config('askmydocs.schedule.<slot>.*')` and registers the
 * corresponding `Schedule::command()` invocation. Used by:
 *
 *  - `bootstrap/app.php` at boot — the production registration path.
 *  - PHPUnit `TierOneSchedulerEnvOverrideTest` — exercises the
 *    config-driven branch without needing Testbench to honour the
 *    project's `withSchedule` closure (Testbench uses its own
 *    bootstrap skeleton; the host closure never fires under tests).
 *
 * Tier-2 (W4 — per-tenant overrides) will compose this registrar via
 * dependency injection (the class is intentionally `final`, so
 * subclassing is NOT part of the Tier-2 design); the slot list
 * itself is canonical and stable.
 */
final class TierOneSchedulerRegistrar
{
    /**
     * Host-side slot list. Order matches the original literal
     * sequence in `bootstrap/app.php` to keep CI-grade telemetry
     * (lock acquisition order, log timestamps) backwards-compatible.
     *
     * Each entry: `[slot_key, command, default_cron]`.
     *
     * @var array<int, array{string, string, string}>
     */
    private const SLOTS = [
        ['kb_prune_embedding_cache', 'kb:prune-embedding-cache', '10 3 * * *'],
        ['chat_log_prune', 'chat-log:prune', '20 3 * * *'],
        ['kb_prune_deleted', 'kb:prune-deleted', '30 3 * * *'],
        ['kb_rebuild_graph', 'kb:rebuild-graph', '40 3 * * *'],
        ['queue_prune_failed', 'queue:prune-failed --hours=48', '0 4 * * *'],
        ['admin_audit_prune', 'admin-audit:prune', '30 4 * * *'],
        ['admin_nonces_prune', 'admin-nonces:prune', '50 4 * * *'],
        ['notifications_prune', 'notifications:prune', '10 4 * * *'],
        ['kb_prune_orphan_files', 'kb:prune-orphan-files --dry-run', '40 4 * * *'],
        ['insights_compute', 'insights:compute', '0 5 * * *'],
    ];

    public function register(Schedule $schedule): void
    {
        foreach (self::SLOTS as [$slot, $command, $defaultCron]) {
            $this->registerSlot($schedule, $slot, $command, $defaultCron);
        }
    }

    /**
     * Register a single slot. Composite-gated callers (e.g.
     * eval:nightly + EVAL_NIGHTLY_ENABLED) call this directly so they
     * can attach `->runInBackground()` etc. on the returned Event.
     *
     * Returns null when the slot is disabled via
     * `config(...enabled = false)`.
     */
    public function registerSlot(
        Schedule $schedule,
        string $slot,
        string $command,
        string $defaultCron,
    ): ?Event {
        if (! (bool) config("askmydocs.schedule.$slot.enabled", true)) {
            return null;
        }

        return $schedule->command($command)
            ->cron((string) config("askmydocs.schedule.$slot.cron", $defaultCron))
            ->onOneServer()
            ->withoutOverlapping();
    }
}

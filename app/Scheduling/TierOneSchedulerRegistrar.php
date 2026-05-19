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
     * Each entry: `[slot_key, command]`. The cron expression lives in
     * `config('askmydocs.schedule.<slot>.cron')` — single source of
     * truth (Copilot iter-3 caught the prior drift hazard between
     * SLOTS literals and `config/askmydocs.php`).
     *
     * @var array<int, array{string, string}>
     */
    private const SLOTS = [
        ['kb_prune_embedding_cache', 'kb:prune-embedding-cache'],
        ['chat_log_prune', 'chat-log:prune'],
        ['kb_prune_deleted', 'kb:prune-deleted'],
        ['kb_rebuild_graph', 'kb:rebuild-graph'],
        ['queue_prune_failed', 'queue:prune-failed --hours=48'],
        ['admin_audit_prune', 'admin-audit:prune'],
        ['admin_nonces_prune', 'admin-nonces:prune'],
        ['notifications_prune', 'notifications:prune'],
        ['kb_prune_orphan_files', 'kb:prune-orphan-files --dry-run'],
        ['insights_compute', 'insights:compute'],
    ];

    public function register(Schedule $schedule): void
    {
        foreach (self::SLOTS as [$slot, $command]) {
            $this->registerSlot($schedule, $slot, $command);
        }
    }

    /**
     * Register a single slot. Composite-gated callers (e.g.
     * eval:nightly + EVAL_NIGHTLY_ENABLED) call this directly so they
     * can attach `->runInBackground()` etc. on the returned Event.
     *
     * Returns null when the slot is disabled via
     * `config(...enabled = false)`. Cron expression comes from
     * `config('askmydocs.schedule.<slot>.cron')`; a missing config
     * entry is a programmer error (slot registered here but absent
     * from `config/askmydocs.php`) and throws.
     */
    public function registerSlot(
        Schedule $schedule,
        string $slot,
        string $command,
    ): ?Event {
        if (! (bool) config("askmydocs.schedule.$slot.enabled", true)) {
            return null;
        }

        $cron = (string) config("askmydocs.schedule.$slot.cron", '');
        if ($cron === '') {
            throw new \RuntimeException(
                "Tier-1 slot `{$slot}` has no `cron` entry in `config('askmydocs.schedule')`. "
                .'Add it to `config/askmydocs.php` and document the matching '
                .'`SCHEDULE_*_CRON` env var in `.env.example`.'
            );
        }

        return $schedule->command($command)
            ->cron($cron)
            ->onOneServer()
            ->withoutOverlapping();
    }
}

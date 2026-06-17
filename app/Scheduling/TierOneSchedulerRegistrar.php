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
        ['kb_health_recompute', 'kb:health-recompute'],
        ['queue_prune_failed', 'queue:prune-failed --hours=48'],
        ['admin_audit_prune', 'admin-audit:prune'],
        ['admin_nonces_prune', 'admin-nonces:prune'],
        ['notifications_prune', 'notifications:prune'],
        ['kb_prune_orphan_files', 'kb:prune-orphan-files --dry-run'],
        ['insights_compute', 'insights:compute'],
        // v8.15/W1 — daily engagement snapshot.
        ['engagement_compute', 'engagement:compute'],
        ['widget_prune_sessions', 'widget:prune-sessions'],
        ['compliance_digest_quarterly', 'compliance:digest-quarterly'],
        // v8.7/W2 — stale-review sweep + weekly notification digest.
        ['kb_stale_review_sweep', 'kb:stale-review-sweep'],
        ['notifications_digest_weekly', 'notifications:digest-weekly'],
        // v8.15/W2 — rich engagement digest (email + Discord/Slack/Teams).
        ['digest_weekly', 'digest:send --frequency=weekly'],
        ['digest_monthly', 'digest:send --frequency=monthly'],
        ['digest_prune_feed', 'digest:prune-feed'],
        // v8.15/W5 — gamification badge awarding (opt-in; no-op when disabled).
        ['gamification_recompute', 'gamification:recompute'],
        // v8.7/W5 — Cloud Time Machine archived-version retention.
        ['kb_prune_archived_versions', 'kb:prune-archived-versions'],
        // v8.11/P9 — scheduled Auto-Wiki maintenance (index rebuild + lint + backfill).
        ['kb_wiki_maintain', 'kb:wiki-maintain'],
        // v8.14 — FinOps maintenance: snapshot watched-model prices, evaluate
        // budget alert thresholds, prune the usage ledger past its retention
        // window (defaults to config('ai-finops.storage.retention_days')).
        ['finops_capture_prices', 'ai-finops:capture-prices'],
        ['finops_check_alerts', 'ai-finops:check-alerts'],
        ['finops_prune_ledger', 'ai-finops:prune'],
    ];

    public function register(Schedule $schedule): void
    {
        foreach (self::SLOTS as [$slot, $command]) {
            $this->registerSlot($schedule, $slot, $command);
        }
    }

    /**
     * Read-only access to the canonical SLOT list for callers that
     * need to iterate the host-side slot inventory (e.g. the
     * `MaintenanceCommandController::schedulerStatus()` ops widget
     * builds its response from this list, avoiding the drift hazard
     * a duplicated map would create — Copilot iter-3 #L242).
     *
     * @return array<int, array{string, string}>  list of [slot_key, command]
     */
    public static function slots(): array
    {
        return self::SLOTS;
    }

    /**
     * Composite-gated slots — `bootstrap/app.php` registers them
     * conditionally on an upstream env flag IN ADDITION to the
     * Tier-1 `SCHEDULE_*_ENABLED` knob. Returned as
     * `[slot_key, command, composite_gate_config_key]` so callers
     * (the ops widget) can mirror the same dual-gate behaviour by
     * reading the gate via `config(<key>)` rather than calling
     * `env(...)` at request time (Copilot iter-5 — request-time
     * `env()` lookups bypass `php artisan config:cache` and can
     * return null in production after a cache build). The config
     * value itself is bound at config-load time to the same env
     * source bootstrap reads.
     *
     * @return array<int, array{string, string, string}>
     */
    public static function compositeGatedSlots(): array
    {
        return [
            ['eval_nightly', 'eval:nightly', 'askmydocs.composite_gates.eval_nightly'],
            ['ai_act_regulatory_poll', 'ai-act:regulatory-poll', 'askmydocs.composite_gates.ai_act_regulatory_poll'],
        ];
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

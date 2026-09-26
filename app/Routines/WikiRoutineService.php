<?php

declare(strict_types=1);

namespace App\Routines;

use Illuminate\Support\Facades\Cache;
use Padosoft\Routines\Models\Routine;
use Padosoft\Routines\Models\RoutineRun;
use Padosoft\Routines\RoutineManager;

/**
 * v8.39/W5 (ADR 0033 §4/§8) — the ONE core behind every AskMyDocs-specific
 * wiki-routine surface (`kb:wiki-routine`, `GET|POST /api/admin/kb/wiki-routine`,
 * `KbWikiRoutineStatusTool`). None of them talk to `RoutineManager` or the
 * `routines`/`routine_runs` tables directly — they all delegate here (R44:
 * one core, thin surfaces).
 *
 * Tenant-scoped (R30) by filtering on `organization_id` — the package's own
 * generic column, opaque to it, where this app's `tenant_id` lives (ADR
 * 0033 §4). The engine has no concept of a tenant; that scoping is entirely
 * this service's job, exactly as it would be for any other third-party
 * table the host doesn't own.
 */
final class WikiRoutineService
{
    public function __construct(private readonly RoutineManager $routines) {}

    public function enabled(): bool
    {
        return (bool) config('kb.wiki_routine.enabled', false);
    }

    /**
     * @return array{provisioned: bool, status?: string, name?: string, next_run_at?: string|null, last_run?: array<string, mixed>|null}
     */
    public function status(string $tenantId): array
    {
        $routine = $this->findRoutine($tenantId);
        if ($routine === null) {
            return ['provisioned' => false];
        }

        $lastRun = RoutineRun::query()
            ->where('routine_id', $routine->id)
            ->orderByDesc('created_at')
            ->first();

        return [
            'provisioned' => true,
            'status' => $routine->statusEnum()->value,
            'name' => $routine->name,
            'next_run_at' => $routine->next_run_at?->toIso8601String(),
            'last_run' => $lastRun === null ? null : [
                'id' => $lastRun->id,
                'outcome' => $lastRun->outcome,
                'message' => $lastRun->message,
                'started_at' => $lastRun->started_at?->toIso8601String(),
                'finished_at' => $lastRun->finished_at?->toIso8601String(),
                // A run stuck in Paused (ADR 0033 §7: currently unreachable
                // in practice, since nothing this cycle grants a mandate to
                // exceed — but the field is real and reported honestly if
                // it were ever set by a future capability).
                'pending_question' => $lastRun->question,
            ],
        ];
    }

    /**
     * @return array{id: string, outcome: ?string, message: ?string}
     */
    public function run(string $tenantId): array
    {
        $routine = $this->ensureRoutine($tenantId);
        $run = $this->routines->fireNow($routine);

        if ($run === null) {
            // OverlapPolicy::Skip (the routine's own default) skipped this
            // fire because a previous one is still running — not an error.
            return ['id' => '', 'outcome' => 'skipped', 'message' => 'A previous run is still in progress.'];
        }

        return ['id' => $run->id, 'outcome' => $run->outcome, 'message' => $run->message];
    }

    /**
     * The cache lock key that serializes provisioning for one tenant.
     * `routines` carries no unique constraint on `(target_type,
     * organization_id)` — a plain find-then-create here is a TOCTOU race:
     * two concurrent calls (a double-click on "Run now", or a concurrent
     * HTTP + CLI trigger) can both see "not provisioned" and both insert a
     * `Routine` row for the same tenant, producing duplicate cron-fired
     * maintenance runs from then on (subagent review, PR #512). Public so
     * a test can hold the exact same lock externally to prove exclusion,
     * mirroring {@see \App\Support\Kb\SourceKeyLock}'s pattern.
     */
    public static function provisionLockKey(string $tenantId): string
    {
        return 'wiki-routine-provision:'.$tenantId;
    }

    private function ensureRoutine(string $tenantId): Routine
    {
        return Cache::lock(self::provisionLockKey($tenantId), 15)->block(10, function () use ($tenantId): Routine {
            $existing = $this->findRoutine($tenantId);
            if ($existing !== null) {
                return $existing;
            }

            // No mandate granted here — ADR 0033 §7: the routine runs as the
            // application, the same authority kb:wiki-maintain's cron already
            // has today. Documented gap, not an oversight.
            return $this->routines->create([
                'owner' => 'system:askmydocs-wiki-maintenance',
                'organization_id' => $tenantId,
                'name' => "Wiki maintenance — {$tenantId}",
                'target_type' => WikiMaintenanceRoutineTarget::TYPE,
                'target_payload' => ['tenant' => $tenantId],
                'trigger_kind' => 'cron',
                'cron' => (string) config('askmydocs.schedule.kb_wiki_maintain.cron', '40 4 * * *'),
                'initiation' => 'system',
            ]);
        });
    }

    private function findRoutine(string $tenantId): ?Routine
    {
        return Routine::query()
            ->where('target_type', WikiMaintenanceRoutineTarget::TYPE)
            ->where('organization_id', $tenantId)
            ->first();
    }
}

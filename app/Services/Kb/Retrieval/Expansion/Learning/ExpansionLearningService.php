<?php

declare(strict_types=1);

namespace App\Services\Kb\Retrieval\Expansion\Learning;

use App\Models\KbExpansionEvent;
use App\Models\KbExpansionModel;
use App\Models\KbExpansionTuning;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * W7 (ADR 0035 addendum — the expansion learning loop) — the ONE core behind
 * `kb:expansion-learn`, `/api/admin/kb/expansion-learning/*` and the read-only
 * `KbExpansionLearningTool` (R44). Every method is tenant-scoped (R30).
 *
 * The loop, end to end:
 *
 *   1. SIGNAL   {@see ExpansionSignalRecorder} stores, per expansion that ran,
 *               what each variant type (lex / vec / hyde) contributed to the
 *               final top-k — `unique_in_top` is the reward.
 *   2. ANALYSE  {@see analyze()} turns a window of events into a PROPOSAL:
 *               per-type weight multipliers (types that keep finding new
 *               relevant chunks weigh more, types that never do weigh less)
 *               and up to {@see MAX_FEW_SHOT} real, masked examples of good
 *               rewrites for the expansion prompt. No GPU, no training.
 *   3. GATE     {@see ExpansionTuningGate} (default: benchmark v2, baseline vs
 *               candidate). Auto-apply happens ONLY on `passed` and only with
 *               `kb.expansion_learning.auto_apply`; a human may apply any
 *               proposal, with the gate result recorded next to the decision.
 *   4. APPLY    one `applied` tuning per (tenant, project); applying supersedes
 *               the previous one, rejecting / rolling back is one call.
 *   5. TRAIN    {@see train()} builds an SFT dataset from the rewarded events
 *               and hands it to a backend (`export` JSONL — default — or the
 *               OpenAI fine-tuning API). Requires the tenant's explicit
 *               training consent (operator-listed) — fail-closed.
 *   6. PROMOTE  a `ready` candidate becomes the tenant's expansion model only
 *               by a human {@see promote()}; {@see retire()} rolls back.
 *
 * What this class can NOT do by itself (stated, not hidden): run the GPU
 * training (the `export` backend leaves it to an external trainer) and prove
 * a trained model better on the tenant's REAL corpus — the gate measures the
 * labelled benchmark corpus.
 */
final class ExpansionLearningService
{
    public const MIN_WEIGHT = 0.25;

    public const MAX_WEIGHT = 1.5;

    public const MAX_FEW_SHOT = 4;

    private const TYPES = ['lex', 'vec', 'hyde'];

    public function __construct(
        private readonly ExpansionTuningGate $gate,
    ) {}

    public function enabled(): bool
    {
        return ExpansionSignalRecorder::enabled();
    }

    /**
     * @return array{enabled: bool, auto_apply: bool, events: array<string,mixed>, applied: array<string,mixed>|null, proposals: list<array<string,mixed>>, models: list<array<string,mixed>>, training_consent: bool}
     */
    public function status(string $tenantId, ?string $projectKey = null): array
    {
        $events = KbExpansionEvent::query()->forTenant($tenantId)
            ->when($projectKey !== null, fn ($q) => $q->where('project_key', $projectKey));

        return [
            'enabled' => $this->enabled(),
            'auto_apply' => (bool) config('kb.expansion_learning.auto_apply', false),
            'training_consent' => $this->hasTrainingConsent($tenantId),
            'events' => [
                'total' => (clone $events)->count(),
                'last_30_days' => (clone $events)->where('created_at', '>=', now()->subDays(30))->count(),
                'with_feedback' => (clone $events)->whereNotNull('feedback')->count(),
            ],
            'applied' => $this->present(KbExpansionTuning::query()->forTenant($tenantId)
                ->when($projectKey !== null, fn ($q) => $q->where('project_key', $projectKey))
                ->where('status', 'applied')->orderByDesc('decided_at')->first()),
            'proposals' => KbExpansionTuning::query()->forTenant($tenantId)
                ->when($projectKey !== null, fn ($q) => $q->where('project_key', $projectKey))
                ->orderByDesc('id')->limit(10)->get()->map(fn (KbExpansionTuning $t): array => $this->present($t))->all(),
            'models' => KbExpansionModel::query()->forTenant($tenantId)->orderByDesc('id')->limit(10)->get()
                ->map(fn (KbExpansionModel $m): array => $this->presentModel($m))->all(),
        ];
    }

    /**
     * Analyse the event window of one project and record a proposal.
     * Returns null when there is not enough evidence (`min_events`).
     *
     * @return array<string,mixed>|null
     */
    public function analyze(string $tenantId, string $projectKey, string $actor, bool $runGate = true): ?array
    {
        $this->assertEnabled();
        $window = max(1, (int) config('kb.expansion_learning.window_days', 30));
        $minEvents = max(1, (int) config('kb.expansion_learning.min_events', 30));

        $totals = array_fill_keys(self::TYPES, ['events_with_lists' => 0, 'events_with_unique' => 0, 'unique_in_top' => 0]);
        $events = 0;
        $refused = 0;
        $candidates = [];

        KbExpansionEvent::query()->forTenant($tenantId)
            ->where('project_key', $projectKey)
            ->where('created_at', '>=', now()->subDays($window))
            ->select(['id', 'query_hash', 'masked_query', 'expansion', 'list_stats', 'refused', 'feedback'])
            ->chunkById(500, function ($rows) use (&$totals, &$events, &$refused, &$candidates): void {
                foreach ($rows as $row) {
                    $events++;
                    $refused += $row->refused ? 1 : 0;
                    $reward = 0;
                    foreach (self::TYPES as $type) {
                        $s = (array) (($row->list_stats ?? [])[$type] ?? []);
                        if ((int) ($s['lists'] ?? 0) === 0) {
                            continue;
                        }
                        $unique = max(0, (int) ($s['unique_in_top'] ?? 0));
                        $totals[$type]['events_with_lists']++;
                        $totals[$type]['events_with_unique'] += $unique > 0 ? 1 : 0;
                        $totals[$type]['unique_in_top'] += $unique;
                        $reward += $unique;
                    }
                    if ($reward > 0 && ! $row->refused && $row->feedback !== -1) {
                        $candidates[$row->query_hash] ??= ['reward' => $reward + ($row->feedback === 1 ? 2 : 0), 'row' => $row];
                    }
                }
            });

        if ($events < $minEvents) {
            return null;
        }

        $weights = [];
        $typeEvidence = [];
        foreach (self::TYPES as $type) {
            $n = $totals[$type]['events_with_lists'];
            $hitRate = $n > 0 ? $totals[$type]['events_with_unique'] / $n : null;
            // No evidence → neutral. Otherwise 0.5 + hit rate: a type that
            // finds something new half of the time keeps its weight (1.0).
            $weights[$type] = $hitRate === null ? 1.0 : round(max(self::MIN_WEIGHT, min(self::MAX_WEIGHT, 0.5 + $hitRate)), 3);
            $typeEvidence[$type] = $totals[$type] + ['hit_rate' => $hitRate === null ? null : round($hitRate, 4)];
        }

        uasort($candidates, static fn (array $a, array $b): int => $b['reward'] <=> $a['reward']);
        $fewShot = array_values(array_map(fn (array $c): array => $this->example($c['row']), array_slice($candidates, 0, self::MAX_FEW_SHOT)));

        $tuning = ['weights' => $weights, 'few_shot' => $fewShot];
        $gate = $runGate ? $this->gate->evaluate($tuning) : ['status' => 'skipped'];

        $proposal = KbExpansionTuning::query()->create([
            'tenant_id' => $tenantId,
            'project_key' => $projectKey,
            'status' => 'proposed',
            'weights' => $weights,
            'few_shot' => $fewShot,
            'evidence' => ['events_analyzed' => $events, 'window_days' => $window, 'refusal_rate' => round($refused / $events, 4), 'types' => $typeEvidence],
            'gate' => $gate,
            'proposed_by' => mb_substr($actor, 0, 120),
        ]);

        if ((bool) config('kb.expansion_learning.auto_apply', false) && ($gate['status'] ?? null) === 'passed') {
            return $this->apply($tenantId, (int) $proposal->id, 'system:auto-apply');
        }

        return $this->present($proposal);
    }

    /** @return array<string,mixed> */
    public function apply(string $tenantId, int $tuningId, string $actor): array
    {
        $this->assertEnabled();

        $applied = DB::transaction(function () use ($tenantId, $tuningId, $actor): KbExpansionTuning {
            $tuning = KbExpansionTuning::query()->forTenant($tenantId)->lockForUpdate()->findOrFail($tuningId);
            if ($tuning->status !== 'proposed') {
                throw new InvalidArgumentException("Only a proposed tuning can be applied (this one is {$tuning->status}).");
            }
            KbExpansionTuning::query()->forTenant($tenantId)
                ->where('project_key', $tuning->project_key)->where('status', 'applied')
                ->update(['status' => 'superseded', 'updated_at' => now()]);
            $tuning->forceFill(['status' => 'applied', 'decided_by' => mb_substr($actor, 0, 120), 'decided_at' => now()])->save();

            return $tuning;
        });
        ActiveExpansionTuning::forget($tenantId, $applied->project_key);

        return $this->present($applied);
    }

    /** Reject a proposal, or roll back the applied tuning (back to neutral weights). */
    public function reject(string $tenantId, int $tuningId, string $actor): array
    {
        $this->assertEnabled();
        $tuning = KbExpansionTuning::query()->forTenant($tenantId)->findOrFail($tuningId);
        if (! in_array($tuning->status, ['proposed', 'applied'], true)) {
            throw new InvalidArgumentException("A {$tuning->status} tuning cannot be rejected.");
        }
        $tuning->forceFill(['status' => 'rejected', 'decided_by' => mb_substr($actor, 0, 120), 'decided_at' => now()])->save();
        ActiveExpansionTuning::forget($tenantId, $tuning->project_key);

        return $this->present($tuning);
    }

    /** Human relevance label on one event (+1 helpful / −1 harmful / 0 clear). */
    public function feedback(string $tenantId, int $eventId, int $value, ?int $userId): array
    {
        $this->assertEnabled();
        if (! in_array($value, [-1, 0, 1], true)) {
            throw new InvalidArgumentException('feedback must be -1, 0 or 1.');
        }
        $event = KbExpansionEvent::query()->forTenant($tenantId)->findOrFail($eventId);
        $event->forceFill(['feedback' => $value === 0 ? null : $value, 'feedback_by' => $value === 0 ? null : $userId])->save();

        return ['id' => $event->id, 'feedback' => $event->feedback];
    }

    /**
     * The SFT dataset (OpenAI chat JSONL): rewarded, non-refused, not-harmful
     * events; the assistant turn keeps only the variant TYPES that earned a
     * unique top-k chunk, so the model learns the rewrites that worked.
     *
     * @return list<array{messages: list<array{role: string, content: string}>}>
     */
    public function dataset(string $tenantId, ?string $projectKey = null, int $limit = 5000): array
    {
        $rows = [];
        KbExpansionEvent::query()->forTenant($tenantId)
            ->when($projectKey !== null, fn ($q) => $q->where('project_key', $projectKey))
            ->where('refused', false)
            ->where(fn ($q) => $q->whereNull('feedback')->orWhere('feedback', '!=', -1))
            ->select(['id', 'masked_query', 'expansion', 'list_stats'])
            ->chunkById(500, function ($events) use (&$rows, $limit): bool {
                foreach ($events as $event) {
                    $answer = [];
                    foreach (self::TYPES as $type) {
                        $unique = (int) (((array) ($event->list_stats ?? []))[$type]['unique_in_top'] ?? 0);
                        $value = ((array) $event->expansion)[$type] ?? null;
                        $answer[$type] = $unique > 0 ? $value : ($type === 'hyde' ? null : []);
                    }
                    if ($answer['lex'] === [] && $answer['vec'] === [] && $answer['hyde'] === null) {
                        continue;
                    }
                    $rows[] = ['messages' => [
                        ['role' => 'system', 'content' => \App\Services\Kb\Retrieval\Expansion\QueryExpansionService::systemPrompt()],
                        ['role' => 'user', 'content' => "<query>\n".str_ireplace('</query', '< /query', (string) $event->masked_query)."\n</query>"],
                        ['role' => 'assistant', 'content' => (string) json_encode($answer, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
                    ]];
                    if (count($rows) >= $limit) {
                        return false;
                    }
                }

                return true;
            });

        return $rows;
    }

    /**
     * Build the dataset and hand it to a training backend. Fail-closed on a
     * missing consent, an unknown backend or too few rows.
     *
     * @return array<string,mixed>
     */
    public function train(string $tenantId, ?string $projectKey, string $backendName, ?string $baseModel, string $actor): array
    {
        $this->assertEnabled();
        $backend = $this->backend($backendName);
        if ($backend->name() !== 'export' && ! $this->hasTrainingConsent($tenantId)) {
            throw new InvalidArgumentException('This tenant has not consented to sending its (masked) queries to a training provider (KB_EXPANSION_TRAINING_CONSENTED_TENANTS).');
        }
        $base = $baseModel ?? (string) config('kb.expansion_learning.training.base_model', OpenAiFineTuningBackend::BASE_MODELS[0]);
        if ($backend->name() === 'openai' && ! in_array($base, OpenAiFineTuningBackend::BASE_MODELS, true)) {
            throw new InvalidArgumentException('Base model not allowed. Allowed: '.implode(', ', OpenAiFineTuningBackend::BASE_MODELS));
        }

        $rows = $this->dataset($tenantId, $projectKey);
        $minRows = max(10, (int) config('kb.expansion_learning.training.min_rows', 50));
        if (count($rows) < $minRows) {
            throw new InvalidArgumentException(sprintf('Only %d rewarded examples; at least %d are required to train.', count($rows), $minRows));
        }

        $contents = implode("\n", array_map(static fn (array $r): string => (string) json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $rows))."\n";
        $disk = (string) config('kb.expansion_learning.training.disk', 'local');
        $path = sprintf('expansion-training/%s/%s.jsonl', preg_replace('/[^A-Za-z0-9_-]/', '_', $tenantId), (string) Str::uuid());
        if (Storage::disk($disk)->put($path, $contents) !== true) {
            // R4 — a dataset that was not written must not become a "submitted" run.
            throw new RuntimeException('The training dataset could not be written to the configured disk.');
        }

        $run = KbExpansionModel::query()->create([
            'tenant_id' => $tenantId,
            'project_key' => $projectKey,
            'backend' => $backend->name(),
            'base_model' => $base,
            'dataset_path' => $path,
            'dataset_rows' => count($rows),
            'status' => 'pending',
            'created_by' => mb_substr($actor, 0, 120),
        ]);
        $backend->submit($run, $contents);

        return $this->presentModel($run->refresh());
    }

    /** @return array<string,mixed> */
    public function refresh(string $tenantId, int $modelId): array
    {
        $this->assertEnabled();
        $run = KbExpansionModel::query()->forTenant($tenantId)->findOrFail($modelId);
        $this->backend($run->backend)->refresh($run);

        return $this->presentModel($run->refresh());
    }

    /**
     * Human-gated: a `ready` candidate becomes the tenant's expansion model
     * (the previous promoted one is retired). Optionally gated on the benchmark.
     *
     * @return array<string,mixed>
     */
    public function promote(string $tenantId, int $modelId, string $actor): array
    {
        $this->assertEnabled();
        $promoted = DB::transaction(function () use ($tenantId, $modelId, $actor): KbExpansionModel {
            $run = KbExpansionModel::query()->forTenant($tenantId)->lockForUpdate()->findOrFail($modelId);
            if ($run->status !== 'ready' || ! is_string($run->model_id) || $run->model_id === '') {
                throw new InvalidArgumentException('Only a ready candidate with a provider model id can be promoted.');
            }
            KbExpansionModel::query()->forTenant($tenantId)->where('status', 'promoted')
                ->update(['status' => 'retired', 'updated_at' => now()]);
            $run->forceFill(['status' => 'promoted', 'promoted_by' => mb_substr($actor, 0, 120), 'promoted_at' => now()])->save();

            return $run;
        });
        ActiveExpansionTuning::forget($tenantId, null);

        return $this->presentModel($promoted);
    }

    /** Roll back a promoted model (or discard a candidate). */
    public function retire(string $tenantId, int $modelId, string $actor): array
    {
        $this->assertEnabled();
        $run = KbExpansionModel::query()->forTenant($tenantId)->findOrFail($modelId);
        $run->forceFill(['status' => 'retired', 'promoted_by' => $run->promoted_by ?? mb_substr($actor, 0, 120)])->save();
        ActiveExpansionTuning::forget($tenantId, null);

        return $this->presentModel($run);
    }

    /** Retention: delete events older than `kb.expansion_learning.retention_days`. Returns the count. */
    public function prune(?int $days = null): int
    {
        $days ??= (int) config('kb.expansion_learning.retention_days', 90);
        if ($days <= 0) {
            return 0;
        }
        $deleted = 0;
        // Deliberately cross-tenant: retention is a deployment-wide policy and
        // deletes by age only (same posture as the other prune commands).
        KbExpansionEvent::query()->withoutGlobalScopes()->where('created_at', '<', now()->subDays($days))
            ->select('id')->chunkById(1000, function ($rows) use (&$deleted): void {
                $deleted += KbExpansionEvent::query()->withoutGlobalScopes()->whereIn('id', $rows->pluck('id'))->delete();
            });

        return $deleted;
    }

    /**
     * (tenant, project) pairs with enough recent events to analyse — the
     * scheduler's work list. Cross-tenant by design; each pair is then
     * analysed strictly within its own tenant.
     *
     * @return list<array{tenant_id: string, project_key: string}>
     */
    public function analysablePairs(): array
    {
        return KbExpansionEvent::query()->withoutGlobalScopes()
            ->whereNotNull('project_key')
            ->where('created_at', '>=', now()->subDays(max(1, (int) config('kb.expansion_learning.window_days', 30))))
            ->groupBy('tenant_id', 'project_key')
            ->havingRaw('COUNT(*) >= ?', [max(1, (int) config('kb.expansion_learning.min_events', 30))])
            ->get(['tenant_id', 'project_key'])
            ->map(static fn ($r): array => ['tenant_id' => (string) $r->tenant_id, 'project_key' => (string) $r->project_key])
            ->all();
    }

    public function hasTrainingConsent(string $tenantId): bool
    {
        return in_array($tenantId, (array) config('kb.expansion_learning.training.consented_tenants', []), true);
    }

    private function backend(string $name): ExpansionTrainingBackend
    {
        return match ($name) {
            'export' => app(ExportJsonlTrainingBackend::class),
            'openai' => app(OpenAiFineTuningBackend::class),
            default => throw new InvalidArgumentException("Unknown training backend [{$name}]; expected export or openai."),
        };
    }

    private function assertEnabled(): void
    {
        if (! $this->enabled()) {
            throw new ExpansionLearningDisabled();
        }
    }

    /** @return array<string,mixed> */
    private function example(KbExpansionEvent $row): array
    {
        $expansion = (array) $row->expansion;
        $stats = (array) $row->list_stats;
        $keep = static fn (string $type): bool => (int) ($stats[$type]['unique_in_top'] ?? 0) > 0;

        return [
            'query' => (string) $row->masked_query,
            'lex' => $keep('lex') ? array_values((array) ($expansion['lex'] ?? [])) : [],
            'vec' => $keep('vec') ? array_values((array) ($expansion['vec'] ?? [])) : [],
            'hyde' => $keep('hyde') && is_string($expansion['hyde'] ?? null) ? $expansion['hyde'] : null,
        ];
    }

    /** @return array<string,mixed>|null */
    private function present(?KbExpansionTuning $t): ?array
    {
        return $t === null ? null : [
            'id' => $t->id,
            'project_key' => $t->project_key,
            'status' => $t->status,
            // JSON storage turns 1.0 into 1: present the multipliers as floats.
            'weights' => ActiveExpansionTuning::sanitiseWeights((array) $t->weights),
            'few_shot_count' => count((array) $t->few_shot),
            'few_shot' => $t->few_shot,
            'evidence' => $t->evidence,
            'gate' => $t->gate,
            'proposed_by' => $t->proposed_by,
            'decided_by' => $t->decided_by,
            'decided_at' => $t->decided_at?->toIso8601String(),
            'created_at' => $t->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string,mixed> */
    private function presentModel(KbExpansionModel $m): array
    {
        return [
            'id' => $m->id,
            'project_key' => $m->project_key,
            'backend' => $m->backend,
            'base_model' => $m->base_model,
            'dataset_rows' => $m->dataset_rows,
            'status' => $m->status,
            'failure_reason' => $m->failure_reason,
            'model_id' => $m->model_id,
            'created_by' => $m->created_by,
            'promoted_by' => $m->promoted_by,
            'promoted_at' => $m->promoted_at?->toIso8601String(),
            'created_at' => $m->created_at?->toIso8601String(),
        ];
    }
}

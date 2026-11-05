<?php

declare(strict_types=1);

namespace App\Services\Kb\Retrieval\Expansion\Learning;

use App\Models\KbExpansionModel;
use App\Models\KbExpansionTuning;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * W7 — what the learning loop has APPLIED for a (tenant, project): per-type
 * list-weight multipliers, curated few-shot examples, and the tenant's promoted
 * fine-tuned expansion model. Read on the search hot path, so it is cached
 * (`kb.expansion_learning.cache_ttl`) and forgotten on every apply / promote.
 *
 * Fails safe: no applied row, the feature OFF, or ANY read error → the neutral
 * tuning (multipliers 1.0, no examples, no model) — i.e. exactly the W4
 * behaviour. A learning-loop outage can never change or break retrieval.
 */
final class ActiveExpansionTuning
{
    /** @var array<string, array{weights: array<string,float>, few_shot: list<array<string,mixed>>, tuning_id: int|null}>|null test/gate override, keyed by "*" */
    private static ?array $override = null;

    /** @return array{weights: array{lex: float, vec: float, hyde: float}, few_shot: list<array<string,mixed>>, tuning_id: int|null} */
    public static function for(string $tenantId, ?string $projectKey): array
    {
        if (self::$override !== null) {
            return self::$override['*'];
        }
        if (! ExpansionSignalRecorder::enabled() || $projectKey === null || $projectKey === '') {
            return self::neutral();
        }

        try {
            return Cache::remember(self::key($tenantId, $projectKey), self::ttl(), static function () use ($tenantId, $projectKey): array {
                $row = KbExpansionTuning::query()->forTenant($tenantId)
                    ->where('project_key', $projectKey)->where('status', 'applied')
                    ->orderByDesc('decided_at')->orderByDesc('id')->first();
                if ($row === null) {
                    return self::neutral();
                }

                return [
                    'weights' => self::sanitiseWeights((array) $row->weights),
                    'few_shot' => array_values(array_slice((array) $row->few_shot, 0, ExpansionLearningService::MAX_FEW_SHOT)),
                    'tuning_id' => (int) $row->id,
                ];
            });
        } catch (Throwable) {
            return self::neutral();
        }
    }

    /** The tenant's promoted fine-tuned expansion model id, or null. */
    public static function promotedModel(string $tenantId): ?string
    {
        if (! ExpansionSignalRecorder::enabled()) {
            return null;
        }

        try {
            return Cache::remember('kb:exp-model:'.$tenantId, self::ttl(), static fn (): ?string => KbExpansionModel::query()->forTenant($tenantId)
                ->where('status', 'promoted')->orderByDesc('promoted_at')->orderByDesc('id')->value('model_id'));
        } catch (Throwable) {
            return null;
        }
    }

    public static function forget(string $tenantId, ?string $projectKey): void
    {
        Cache::forget('kb:exp-model:'.$tenantId);
        if ($projectKey !== null) {
            Cache::forget(self::key($tenantId, $projectKey));
        }
    }

    /**
     * Run `$callback` with a fixed tuning in force (the benchmark gate measures
     * a proposal without applying it). Always restored, even on exceptions.
     *
     * @template T
     *
     * @param  array{weights: array<string,float>, few_shot: list<array<string,mixed>>}  $tuning
     * @param  callable(): T  $callback
     * @return T
     */
    public static function with(array $tuning, callable $callback): mixed
    {
        $previous = self::$override;
        self::$override = ['*' => [
            'weights' => self::sanitiseWeights($tuning['weights'] ?? []),
            'few_shot' => array_values((array) ($tuning['few_shot'] ?? [])),
            'tuning_id' => null,
        ]];
        try {
            return $callback();
        } finally {
            self::$override = $previous;
        }
    }

    /** @return array{weights: array{lex: float, vec: float, hyde: float}, few_shot: list<array<string,mixed>>, tuning_id: int|null} */
    public static function neutral(): array
    {
        return ['weights' => ['lex' => 1.0, 'vec' => 1.0, 'hyde' => 1.0], 'few_shot' => [], 'tuning_id' => null];
    }

    /**
     * @param  array<string,mixed>  $weights
     * @return array{lex: float, vec: float, hyde: float}
     */
    public static function sanitiseWeights(array $weights): array
    {
        $out = [];
        foreach (['lex', 'vec', 'hyde'] as $type) {
            $value = $weights[$type] ?? 1.0;
            $out[$type] = is_numeric($value) && is_finite((float) $value)
                ? max(ExpansionLearningService::MIN_WEIGHT, min(ExpansionLearningService::MAX_WEIGHT, (float) $value))
                : 1.0;
        }

        return $out;
    }

    private static function key(string $tenantId, string $projectKey): string
    {
        return 'kb:exp-tuning:'.hash('sha256', $tenantId.'|'.$projectKey);
    }

    private static function ttl(): int
    {
        return max(1, (int) config('kb.expansion_learning.cache_ttl', 300));
    }
}

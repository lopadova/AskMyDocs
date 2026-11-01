<?php

declare(strict_types=1);

namespace App\Agent\Grounding;

use App\Decisions\DecisionState;

/** Lossless partitions: each comparison retains its complete primary source. */
final class FocusedDecisionBatches
{
    public function plan(array $state): array
    {
        if (! DecisionState::fromArray($state)->exceedsLimit()) return ['batches' => [$state], 'overflow' => []];

        $base = [...array_diff_key($state, ['sources' => true, 'pairs' => true]), 'sources' => [], 'pairs' => []];
        $shards = $overflow = [];
        foreach ($state['pairs'] as $pair) {
            $primary = [...$pair, 'related_evidence' => []];
            $seed = [...$base, 'sources' => [$pair['source_ref'] => $state['sources'][$pair['source_ref']]], 'pairs' => [$primary]];
            if (DecisionState::fromArray($seed)->exceedsLimit()) {
                $overflow[$pair['claim_id']] = DecisionState::fromArray($seed)->bytes;
                continue;
            }
            $parts = [];
            $current = $seed;
            foreach ($pair['related_evidence'] ?? [] as $related) {
                $trial = $this->addRelated($current, $related, $state['sources']);
                if (DecisionState::fromArray($trial)->exceedsLimit()) {
                    if ($current['pairs'][0]['related_evidence'] !== []) $parts[] = $current;
                    $trial = $this->addRelated($seed, $related, $state['sources']);
                    if (DecisionState::fromArray($trial)->exceedsLimit()) {
                        $overflow[$pair['claim_id']] = DecisionState::fromArray($trial)->bytes;
                        continue 2; // Never validate a claim after dropping an oversized alternative.
                    }
                }
                $current = $trial;
            }
            $parts[] = $current;
            array_push($shards, ...$parts);
        }
        // Share original text across independent questions when it fits. Two
        // shards of one claim stay separate so no question result is overwritten.
        $batches = [];
        foreach ($shards as $shard) {
            foreach ($batches as $index => $batch) {
                if (array_intersect(array_column($batch['pairs'], 'claim_id'), array_column($shard['pairs'], 'claim_id')) !== []) continue;
                $trial = [...$batch, 'sources' => [...$batch['sources'], ...$shard['sources']], 'pairs' => [...$batch['pairs'], ...$shard['pairs']]];
                if (DecisionState::fromArray($trial)->exceedsLimit()) continue;
                $batches[$index] = $trial;
                continue 2;
            }
            $batches[] = $shard;
        }
        return ['batches' => $batches, 'overflow' => $overflow];
    }

    /** Every partition must support a claim; an error or adverse result vetoes it. */
    public function combine(array $answers): ?array
    {
        if ($answers === []) return null;
        $threshold = (float) config('reasoning.threshold', .8);
        $adverse = array_filter($answers, fn ($answer) => $answer !== null && ($answer['choice'] ?? null) !== 'supported_relevant'
            && ($answer['probabilities'][$answer['choice'] ?? ''] ?? 0) >= $threshold);
        if ($adverse !== []) {
            // Unsupported prose may use an exact-record fallback; a known
            // conflict/off-topic veto must never be hidden behind that path.
            $priority = ['conflicting' => 3, 'supported_off_topic' => 2, 'insufficient_context' => 1, 'unsupported' => 0];
            usort($adverse, fn ($a, $b) => (($priority[$b['choice']] ?? 0) <=> ($priority[$a['choice']] ?? 0))
                ?: (($b['probabilities'][$b['choice']] ?? 0) <=> ($a['probabilities'][$a['choice']] ?? 0)));
            return $adverse[0];
        }
        if (in_array(null, $answers, true)) return null;
        usort($answers, fn ($a, $b) => ($a['probabilities']['supported_relevant'] ?? 0) <=> ($b['probabilities']['supported_relevant'] ?? 0));
        return $answers[0];
    }

    private function addRelated(array $state, array $related, array $sources): array
    {
        $state['sources'][$related['source_ref']] = $sources[$related['source_ref']];
        $state['pairs'][0]['related_evidence'][] = $related;
        return $state;
    }
}

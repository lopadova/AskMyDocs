<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use App\Decisions\DecisionException;
use App\Decisions\DecisionResult;

trait ReadsDecisionInput
{
    /** @return array<string,mixed> */
    private function state(string $text): array
    {
        $raw = $this->option('state');
        if ($raw === null || $raw === '') {
            return ['text' => $text];
        }
        $state = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($state) || array_is_list($state)) {
            throw new DecisionException('--state must be a JSON object.');
        }

        return ['text' => $text] + $state;
    }

    private function threshold(): ?float
    {
        $raw = $this->option('threshold');
        if ($raw === null || $raw === '') {
            return null;
        }
        if (! is_numeric($raw) || (float) $raw <= 0.5 || (float) $raw > 1) {
            throw new DecisionException('--threshold must be greater than 0.5 and at most 1.');
        }

        return (float) $raw;
    }

    private function outputResult(DecisionResult $result, ?string $boolQuestion = null): void
    {
        $data = $result->toArray();
        if ($boolQuestion !== null && ($threshold = $this->threshold()) !== null) {
            $data['boolean'] = $result->toBool($boolQuestion, $threshold);
        }
        if ($this->option('json')) {
            $this->line(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return;
        }
        $this->line('Model: '.$result->model.' | Latency: '.$result->latencyMs.' ms | Cost: '.($result->usage['cost'] ?? 'n/a'));
        foreach ($result->answers as $key => $answer) {
            $this->line($key.': '.json_encode($answer, JSON_UNESCAPED_UNICODE));
        }
        if (array_key_exists('boolean', $data)) {
            $this->info('Decision: '.($data['boolean'] ? 'yes' : 'no'));
        }
    }
}

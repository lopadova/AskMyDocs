<?php

declare(strict_types=1);

namespace App\Decisions\Models;

use App\Decisions\DecisionException;
use App\Decisions\DecisionModel;
use App\Decisions\DecisionResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class JevDecisionModel implements DecisionModel
{
    public function decide(array $state, array $questions): DecisionResult
    {
        $key = config('ai.providers.openrouter.key');
        if (! is_string($key) || $key === '') {
            throw new DecisionException('OpenRouter API key is not configured.');
        }
        $started = microtime(true);
        try {
            $response = Http::withToken($key)
                ->acceptJson()
                ->timeout((int) config('decisions.models.jev.timeout', 15))
                ->post('https://openrouter.ai/api/alpha/decisions', [
                    'model' => config('decisions.models.jev.model'),
                    'state' => $state,
                    'questions' => $questions,
                ]);
        } catch (Throwable $exception) {
            throw new DecisionException('Decision provider is unavailable.', previous: $exception);
        }
        if (! $response->successful()) {
            throw new DecisionException('Decision provider returned HTTP '.$response->status().'.');
        }
        $data = $response->json();
        if (! is_array($data) || ! is_array($data['answers'] ?? null)
            || array_diff(array_keys($data['answers']), array_keys($questions)) !== []
            || array_diff(array_keys($questions), array_keys($data['answers'])) !== []
            || ! is_string($data['model'] ?? null) || $data['model'] === '') {
            throw new DecisionException('Decision provider returned a malformed response.');
        }
        $answers = [];
        foreach ($questions as $keyName => $question) {
            $answer = $data['answers'][$keyName];
            if (! is_array($answer) || ($answer['type'] ?? null) !== $question['type']) {
                throw new DecisionException('Decision provider returned an invalid answer type.');
            }
            $answers[$keyName] = match ($question['type']) {
                'noul' => $this->noul($answer),
                'choice' => $this->choice($answer, $question['criteria']),
                'score' => $this->score($answer, $question['criteria']),
            };
        }
        $usage = $data['usage'] ?? [];
        if (! is_array($usage)) {
            throw new DecisionException('Decision provider returned invalid usage.');
        }
        foreach (['cost', 'input_tokens', 'output_tokens'] as $field) {
            if (isset($usage[$field]) && (! is_numeric($usage[$field]) || (float) $usage[$field] < 0)) {
                throw new DecisionException('Decision provider returned invalid usage.');
            }
        }
        $latency = round((microtime(true) - $started) * 1000, 2);
        Log::info('Decision model call completed.', [
            'model' => $data['model'], 'question_count' => count($questions),
            'cost' => $usage['cost'] ?? null, 'latency_ms' => $latency,
        ]);

        return new DecisionResult((string) ($data['id'] ?? ''), $data['model'], $answers, $usage, $latency);
    }

    /** @param array<string,mixed> $answer @return array<string,mixed> */
    private function noul(array $answer): array
    {
        $probability = $answer['noul'] ?? null;
        $this->assertProbability($probability);

        return ['type' => 'noul', 'probability_true' => (float) $probability];
    }

    /** @param array<string,mixed> $answer @param array<string,string> $criteria @return array<string,mixed> */
    private function choice(array $answer, array $criteria): array
    {
        if (! is_string($answer['choice'] ?? null) || ! array_key_exists($answer['choice'], $criteria)
            || ! is_array($answer['probabilities'] ?? null)
            || array_diff(array_keys($criteria), array_keys($answer['probabilities'])) !== []
            || array_diff(array_keys($answer['probabilities']), array_keys($criteria)) !== []) {
            throw new DecisionException('Decision provider returned an invalid choice.');
        }
        foreach ($answer['probabilities'] as $probability) {
            $this->assertProbability($probability);
        }
        if (abs(array_sum($answer['probabilities']) - 1.0) > 0.01
            || $answer['probabilities'][$answer['choice']] < max($answer['probabilities'])) {
            throw new DecisionException('Decision provider returned an inconsistent choice distribution.');
        }
        $this->assertProbability($answer['confidence'] ?? null);

        return ['type' => 'choice', 'choice' => $answer['choice'],
            'probabilities' => $answer['probabilities'], 'confidence' => (float) $answer['confidence']];
    }

    /** @param array<string,mixed> $answer @param list<string> $criteria @return array<string,mixed> */
    private function score(array $answer, array $criteria): array
    {
        $score = $answer['score'] ?? null;
        if (! is_numeric($score) || ! is_finite((float) $score) || (float) $score < 0 || (float) $score > count($criteria) - 1
            || ! is_array($answer['probabilities'] ?? null)
            || count($answer['probabilities']) !== count($criteria)
            || array_diff(array_keys($answer['probabilities']), range(0, count($criteria) - 1)) !== []) {
            throw new DecisionException('Decision provider returned an invalid score.');
        }
        foreach (range(0, count($criteria) - 1) as $index) {
            if (! array_key_exists((string) $index, $answer['probabilities'])) {
                throw new DecisionException('Decision provider returned an invalid score distribution.');
            }
            $this->assertProbability($answer['probabilities'][(string) $index]);
        }
        $this->assertProbability($answer['confidence'] ?? null);

        return ['type' => 'score', 'score' => (float) $score,
            'probabilities' => $answer['probabilities'], 'confidence' => (float) $answer['confidence'],
            'criteria' => $criteria];
    }

    private function assertProbability(mixed $value): void
    {
        if (! is_int($value) && ! is_float($value)) {
            throw new DecisionException('Decision provider returned a non-numeric probability.');
        }
        if (! is_finite((float) $value) || $value < 0 || $value > 1) {
            throw new DecisionException('Decision provider returned an out-of-range probability.');
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Decisions\Models;

use App\Decisions\DecisionException;
use App\Decisions\DecisionResult;
use App\Decisions\IndependentDecisionModel;
use App\Services\Dev\AgentRetrievalFixture as Fixture;

/** Finite offline test decisions, never a substitute for a live semantic model. */
final class E2eFixtureDecisionModel implements IndependentDecisionModel
{
    public function decide(array $state, array $questions): DecisionResult
    {
        if (! app()->environment('testing') || ! config('decisions.e2e_fixture_enabled') || config('ai.default') !== 'fake') {
            throw new DecisionException('Offline decision fixture requires the explicit testing profile.');
        }
        $question = $state['question'] ?? $state['original_question'] ?? $state['current_question'] ?? null;
        if (! in_array($question, [...Fixture::QUESTIONS, Fixture::POLICY_QUESTION], true)) {
            throw new DecisionException('Question is outside the finite offline fixture.');
        }
        $answers = [];
        foreach ($questions as $key => $spec) {
            if (isset($state['pairs']) && preg_match('/^claim_\d+$/D', $key)) {
                $pair = collect($state['pairs'])->firstWhere('claim_id', $key);
                $source = $state['sources'][$pair['source_ref'] ?? ''] ?? null;
                $supported = $pair !== null && $this->supported($pair['claim'] ?? '', $source, $question);
                foreach ($pair['related_evidence'] ?? [] as $related) {
                    // Changed/unknown sibling records must abstain, never hide a conflict.
                    $this->supported($pair['claim'] ?? '', $state['sources'][$related['source_ref'] ?? ''] ?? null, $question);
                }
                $expected = ['supported_relevant', 'supported_off_topic', 'unsupported', 'conflicting', 'insufficient_context'];
                if (($spec['type'] ?? null) !== 'choice' || array_diff($expected, array_keys($spec['criteria'] ?? [])) !== []
                    || array_diff(array_keys($spec['criteria'] ?? []), $expected) !== []) throw new DecisionException('Unknown fixture criteria.');
                $choice = $supported ? 'supported_relevant' : 'unsupported';
                $answers[$key] = ['type' => 'choice', 'choice' => $choice, 'confidence' => 1.0,
                    'probabilities' => array_fill_keys($expected, 0.0)];
                $answers[$key]['probabilities'][$choice] = 1.0;
                continue;
            }
            $criteria = array_keys($spec['criteria'] ?? []);
            sort($criteria);
            if (($spec['type'] ?? null) !== 'noul' || $criteria !== ['false', 'true']) throw new DecisionException('Unknown fixture decision type.');
            if (isset($state['candidates']) && preg_match('/^relevance_(\d+)$/D', $key, $match)) {
                $candidate = $state['candidates'][(int) $match[1]] ?? [];
                $value = $this->supported($candidate['claim'] ?? '', $state['sources'][$candidate['source_ref'] ?? ''] ?? null, $question);
            } elseif (isset($state['source_verified_statements']) && preg_match('/^denial_(\d+)$/D', $key, $match)) {
                $text = $state['source_verified_statements'][(int) $match[1]]['text'] ?? '';
                if (! $this->canonicalText($text)) throw new DecisionException('Unknown fixture statement.');
                $value = false;
            } elseif (isset($state['answer'], $state['sources']) && ($key === 'covered' || $key === 'material_in_partition' || preg_match('/^task_\d+$/D', $key))) {
                if (preg_match('/^task_(\d+)$/D', $key, $taskMatch)) {
                    $task = $state['requested_tasks'][(int) $taskMatch[1]] ?? null;
                    if (! is_array($task) || ($task['question'] ?? null) !== $question) {
                        throw new DecisionException('Unknown fixture coverage task.');
                    }
                }
                $records = $this->records($state['sources']);
                $policy = $this->hasPolicy($state['sources']);
                if ($key === 'material_in_partition') {
                    $value = $records !== [] || $policy;
                } elseif ($question === Fixture::POLICY_QUESTION) {
                    $value = $policy && in_array(Fixture::POLICY, $state['answer'], true);
                } else {
                    $value = $records !== [] && $question !== Fixture::QUESTIONS[1];
                    foreach ($records as $record) {
                        $value = $value && (in_array(Fixture::orderText($record), $state['answer'], true)
                            || in_array(Fixture::orderText($record, false), $state['answer'], true));
                    }
                }
            } elseif (isset($state['unqueried_sources']) && preg_match('/^(?:live_records|required)_(\d+)$/D', $key, $match)) {
                $source = $state['unqueried_sources'][(int) $match[1]] ?? null;
                if (! is_array($source) || ! str_contains(json_encode($source, JSON_THROW_ON_ERROR), 'get_orders')) {
                    throw new DecisionException('Unknown fixture source inventory.');
                }
                $value = in_array($question, Fixture::QUESTIONS, true);
            } else {
                throw new DecisionException('Decision is outside the finite offline fixture.');
            }
            $answers[$key] = ['type' => 'noul', 'probability_true' => $value ? 1.0 : 0.0];
        }
        return new DecisionResult('fixture-'.hash('sha256', json_encode([$state, $questions], JSON_THROW_ON_ERROR)),
            'e2e-fixture-v1', $answers, ['cost' => 0], 0);
    }

    public function decideIndependently(array $state, array $questions): DecisionResult
    {
        $answers = $errors = [];
        foreach ($questions as $key => $spec) {
            try {
                $result = $this->decide($state, [$key => $spec]);
                $answers += $result->answers;
            } catch (DecisionException $error) {
                $errors[$key] = $error->getMessage();
            }
        }
        return new DecisionResult('fixture-independent-'.hash('sha256', json_encode([$state, $questions], JSON_THROW_ON_ERROR)),
            'e2e-fixture-v1', $answers, ['cost' => 0], 0, $errors);
    }

    private function supported(string $claim, mixed $source, string $question): bool
    {
        if (! is_string($source)) throw new DecisionException('Missing fixture source.');
        $record = Fixture::matchOrder(json_decode($source, true));
        if ($record !== null) return in_array($question, Fixture::QUESTIONS, true)
            && in_array($claim, [Fixture::orderText($record), Fixture::orderText($record, false)], true);
        if ($this->hasPolicy($source)) return $question === Fixture::POLICY_QUESTION && $claim === Fixture::POLICY;
        throw new DecisionException('Source is outside the finite offline fixture.');
    }

    private function canonicalText(string $text): bool
    {
        if ($text === Fixture::POLICY) return true;
        foreach (Fixture::ORDERS as $record) {
            if (in_array($text, [Fixture::orderText($record), Fixture::orderText($record, false)], true)) return true;
        }
        return false;
    }

    private function hasPolicy(mixed $value): bool
    {
        $canonical = static fn ($text) => is_string($text)
            && in_array(trim($text), [Fixture::POLICY, "# Remote Work Policy\n\n".Fixture::POLICY], true);
        // Claim checks receive the original literal source directly.
        if (is_string($value)) return $canonical($value);
        if (! is_array($value)) throw new DecisionException('Unknown fixture source collection.');
        $found = false;
        foreach ($value as $source) {
            if (! is_array($source)) throw new DecisionException('Unknown fixture source shape.');
            if (isset($source['document_id'])) {
                $keys = array_keys($source);
                sort($keys);
                if ($keys !== ['complete_source', 'document_id', 'text', 'title']
                    || ! is_int($source['document_id']) || $source['document_id'] < 1
                    || $source['title'] !== 'Remote Work Policy' || ! is_bool($source['complete_source'])
                    || ! $canonical($source['text'])) throw new DecisionException('Unknown fixture document.');
                $found = true;
            } elseif (array_diff(array_keys($source), ['tool_execution_id', 'source_title', 'observed_at', 'result']) !== []
                || ! is_array($source['result'] ?? null)) {
                throw new DecisionException('Unknown fixture source shape.');
            }
        }
        return $found;
    }

    private function records(mixed $value): array
    {
        if (! is_array($value)) return [];
        if (isset($value['number'])) {
            $record = Fixture::matchOrder($value);
            if ($record === null) throw new DecisionException('Unknown or changed fixture record.');
            return [$record];
        }
        $records = [];
        foreach ($value as $child) array_push($records, ...$this->records($child));
        return $records;
    }
}

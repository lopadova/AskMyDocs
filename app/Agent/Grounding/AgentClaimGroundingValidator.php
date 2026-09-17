<?php

declare(strict_types=1);

namespace App\Agent\Grounding;

/** Validates the evidence-bound claim contract emitted by the agent synthesizer. */
final class AgentClaimGroundingValidator
{
    /**
     * @param array<string,mixed> $evidence
     * @param mixed $claims
     * @return array{valid:bool,reason:?string,terms:list<string>,claims:list<array<string,mixed>>}
     */
    public function validate(string $question, array $evidence, mixed $claims): array
    {
        if (! is_array($claims) || $claims === []) {
            return $this->failure('missing_claims', $this->candidateTerms($question));
        }

        $documents = [];
        foreach (is_array($evidence['documents'] ?? null) ? $evidence['documents'] : [] as $document) {
            if (! is_array($document)) {
                continue;
            }
            foreach (is_array($document['evidence'] ?? null) ? $document['evidence'] : [] as $chunk) {
                if (! is_array($chunk) || ! is_string($chunk['evidence_hash'] ?? null)) {
                    continue;
                }
                $documents[(string) ($document['document_id'] ?? '')][(string) $chunk['evidence_hash']] = (string) ($chunk['content'] ?? '');
            }
        }

        $tools = [];
        foreach (is_array($evidence['api_tools'] ?? null) ? $evidence['api_tools'] : [] as $tool) {
            if (! is_array($tool) || ! is_int($tool['execution_id'] ?? null)) {
                continue;
            }
            $tools[(string) $tool['execution_id']] = [
                'hash' => (string) ($tool['evidence_hash'] ?? ''),
                'content' => json_encode($tool['result'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '',
            ];
        }

        $normalisedClaims = [];
        foreach ($claims as $claim) {
            if (! is_array($claim)) {
                return $this->failure('invalid_claim', $this->candidateTerms($question));
            }
            $text = trim((string) ($claim['text'] ?? ''));
            $quote = trim((string) ($claim['quote'] ?? ''));
            $hash = trim((string) ($claim['evidence_hash'] ?? ''));
            if ($text === '' || $quote === '' || $hash === '') {
                return $this->failure('invalid_claim', $this->candidateTerms($question));
            }

            $documentId = $claim['document_id'] ?? null;
            $executionId = $claim['tool_execution_id'] ?? null;
            if (($documentId === null) === ($executionId === null)) {
                return $this->failure('invalid_claim_source', $this->candidateTerms($question));
            }
            if ($documentId !== null) {
                $content = $documents[(string) $documentId][$hash] ?? null;
                if (! is_string($content) || ! $this->contains($content, $quote)) {
                    return $this->failure('quote_not_in_chunk', $this->candidateTerms($question));
                }
            } else {
                $tool = $tools[(string) $executionId] ?? null;
                if ($tool === null || ! hash_equals($tool['hash'], $hash) || ! $this->contains($tool['content'], $quote)) {
                    return $this->failure('quote_not_in_tool_result', $this->candidateTerms($question));
                }
            }
            $normalisedClaims[] = [
                'text' => $text,
                'quote' => $quote,
                'evidence_hash' => $hash,
                'document_id' => $documentId === null ? null : (int) $documentId,
                'tool_execution_id' => $executionId === null ? null : (int) $executionId,
            ];
        }

        $terms = $this->candidateTerms($question);
        foreach ($terms as $term) {
            if (! array_filter($normalisedClaims, fn (array $claim): bool => $this->contains($claim['quote'], $term))) {
                return $this->failure('unattested_entity', [$term]);
            }
        }

        return ['valid' => true, 'reason' => null, 'terms' => [], 'claims' => $normalisedClaims];
    }

    /** @return list<string> */
    private function candidateTerms(string $question): array
    {
        preg_match_all("/(?<![\\p{L}\\p{N}])(?:[A-ZÀ-ÖØ-Þ][\\p{L}\\p{M}'’_-]{1,}|[A-Z0-9][A-Z0-9_-]{1,})(?![\\p{L}\\p{N}])/u", $question, $matches);
        $ignored = ['che', 'chi', 'come', 'cosa', 'dammi', 'dove', 'fammi', 'mi', 'mostra', 'perche', 'perché', 'quale', 'quali'];

        return array_values(array_unique(array_filter($matches[0] ?? [], static function (string $term) use ($ignored): bool {
            return ! in_array(mb_strtolower($term), $ignored, true);
        })));
    }

    private function contains(string $haystack, string $needle): bool
    {
        $normalise = static fn (string $value): string => mb_strtolower((string) preg_replace('/\\s+/u', ' ', trim($value)));

        return str_contains($normalise($haystack), $normalise($needle));
    }

    /** @return array{valid:false,reason:string,terms:list<string>,claims:list<array<string,mixed>>} */
    private function failure(string $reason, array $terms): array
    {
        return ['valid' => false, 'reason' => $reason, 'terms' => $terms, 'claims' => []];
    }
}

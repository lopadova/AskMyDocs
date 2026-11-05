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
    public function validate(string $question, array $evidence, mixed $claims, array $mentions = []): array
    {
        $terms = array_values(array_unique(array_filter($mentions, static fn (mixed $term): bool => is_string($term)
            && $term !== '' && mb_strlen($term) <= 120
            && str_contains(mb_strtolower($question), mb_strtolower($term)))));
        if (! is_array($claims) || $claims === []) {
            return $this->failure('missing_claims', $terms);
        }

        $documents = [];
        $documentTitles = [];
        foreach (is_array($evidence['documents'] ?? null) ? $evidence['documents'] : [] as $document) {
            if (! is_array($document)) {
                continue;
            }
            foreach (is_array($document['evidence'] ?? null) ? $document['evidence'] : [] as $chunk) {
                if (! is_array($chunk) || ! is_string($chunk['evidence_hash'] ?? null)) {
                    continue;
                }
                $documents[(string) ($document['document_id'] ?? '')][(string) $chunk['evidence_hash']] = (string) ($chunk['content'] ?? '');
                $documentTitles[(string) ($document['document_id'] ?? '')][(string) $chunk['evidence_hash']] = (string) ($chunk['source_title'] ?? $document['title'] ?? '');
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
                'result' => $tool['result'] ?? [],
                'observation' => $tool,
            ];
        }

        $normalisedClaims = [];
        foreach ($claims as $claim) {
            if (! is_array($claim)) {
                return $this->failure('invalid_claim', $terms);
            }
            $text = trim((string) ($claim['text'] ?? ''));
            $quote = trim((string) ($claim['quote'] ?? ''));
            $hash = trim((string) ($claim['evidence_hash'] ?? ''));
            if ($text === '' || $quote === '' || $hash === '') {
                return $this->failure('invalid_claim', $terms);
            }

            $documentId = $claim['document_id'] ?? null;
            $executionId = $claim['tool_execution_id'] ?? null;
            if (($documentId === null) === ($executionId === null)) {
                return $this->failure('invalid_claim_source', $terms);
            }
            if ($documentId !== null) {
                $content = $documents[(string) $documentId][$hash] ?? null;
                if (! is_string($content) || ($quote = $this->literalQuote($content, $quote)) === null) {
                    return $this->failure('quote_not_in_chunk', $terms);
                }
                $referenceSource = $documentTitles[(string) $documentId][$hash]."\n".$content;
            } else {
                $tool = $tools[(string) $executionId] ?? null;
                if ($tool === null || ! hash_equals($tool['hash'], $hash)
                    || ($quote = $this->toolQuote($tool, $quote)) === null) {
                    return $this->failure('quote_not_in_tool_result', $terms);
                }
                // Use the bound record/quote, never identifiers from another
                // record merely present in the same response envelope.
                $referenceSource = $quote;
                $source = $tool['observation'];
                $records = array_values(array_filter(\App\Services\Chat\Reasoning\EvidenceProjector::records($source),
                    fn ($entry) => (! isset($claim['record_path']) || $claim['record_path'] === $entry['path'])
                        && $this->toolQuote(['content' => json_encode($entry['record'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            'result' => $entry['record']], $quote) !== null));
                if (count($records) === 1) {
                    $contexts = (new \App\Agent\Evidence\ToolResultLineage)->contexts($evidence, $source, $records[0]['path'],
                        (string) ($source['tenant_id'] ?? ''), (string) ($source['project_key'] ?? $source['result']['companyKey'] ?? ''));
                    // Only exact identifiers along a verified dependency path can
                    // supply contextual references; no parent measurements are copied.
                    foreach ($contexts as $context) $referenceSource .= "\n".implode("\n", $context['identifiers']);
                }
            }
            // Capitalized alphanumeric code atoms must be copied literally.
            // Semantic support cannot turn AB12 into AB21 within a long claim.
            preg_match_all('/(?<![\pL\pN_])[A-Z]+[A-Z0-9]*[0-9][A-Z0-9]*(?![\pL\pN_])/u', $text, $references);
            foreach (array_unique($references[0]) as $reference) {
                if (! preg_match('/(?<![\pL\pN])'.preg_quote($reference, '/').'(?![\pL\pN])/iu', $referenceSource)) {
                    return $this->failure('unattested_literal_reference', [$reference]);
                }
            }
            $normalisedClaims[] = [
                'text' => $text,
                'quote' => $quote,
                'evidence_hash' => $hash,
                'document_id' => $documentId === null ? null : (int) $documentId,
                'tool_execution_id' => $executionId === null ? null : (int) $executionId,
                ...(isset($claim['record_path']) ? ['record_path' => $claim['record_path']] : []),
            ];
        }

        foreach ($terms as $term) {
            if (! array_filter($normalisedClaims, fn (array $claim): bool => $this->contains($claim['quote'], $term))) {
                return $this->failure('unattested_entity', [$term]);
            }
        }

        return ['valid' => true, 'reason' => null, 'terms' => [], 'claims' => $normalisedClaims];
    }

    private function contains(string $haystack, string $needle): bool
    {
        $normalise = static fn (string $value): string => mb_strtolower((string) preg_replace('/\\s+/u', ' ', trim($value)));

        $haystack = $normalise($haystack);
        $needle = $normalise($needle);
        if (str_contains($haystack, $needle)) {
            return true;
        }

        // The synthesizer sometimes copies a source sentence correctly but
        // substitutes only its final comma/period while closing a quote. The
        // words and Markdown delimiters must still match verbatim; accepting
        // this one cosmetic terminal difference avoids a false refusal while
        // preserving the source/hash binding.
        $withoutTerminalPunctuation = rtrim($needle, ".,;:!?… ");

        return mb_strlen($withoutTerminalPunctuation) >= 16
            && str_contains($haystack, $withoutTerminalPunctuation);
    }

    private function literalQuote(string $content, string $quote): ?string
    {
        if ($this->contains($content, $quote)) {
            return $quote;
        }
        // Markdown emphasis occasionally leaks into a quote copied from a
        // plain-text source. Remove only balanced bold delimiters, then bind
        // the repaired quote to the same authorized source/hash as before.
        $plain = preg_replace('/\*\*(.+?)\*\*/us', '$1', $quote);

        return is_string($plain) && $plain !== $quote && $this->contains($content, $plain)
            ? $plain
            : null;
    }

    /**
     * A tool may return a structured record with additional fields. A quote
     * containing an exact subset of one record is evidence-bound even though
     * its serialized JSON is not a literal substring of the whole result.
     * Never use this relaxation for document text or for a different tool/hash.
     *
     * @param array{content:string,result:mixed} $tool
     */
    private function toolQuote(array $tool, string $quote): ?string
    {
        $literal = $this->literalQuote($tool['content'], $quote);
        if ($literal !== null) {
            return $literal;
        }
        $decoded = json_decode($quote, true);
        if (! is_array($decoded) || $decoded === [] || array_is_list($decoded)) {
            return null;
        }

        return $this->matchesNestedRecord($decoded, $tool['result']) ? $quote : null;
    }

    /** @param array<string,mixed> $expected */
    private function matchesNestedRecord(array $expected, mixed $actual): bool
    {
        if (! is_array($actual)) {
            return false;
        }
        if ($this->isSubset($expected, $actual)) {
            return true;
        }
        foreach ($actual as $child) {
            if ($this->matchesNestedRecord($expected, $child)) {
                return true;
            }
        }

        return false;
    }

    private function isSubset(mixed $expected, mixed $actual): bool
    {
        if (! is_array($expected) || ! is_array($actual)) {
            return $expected === $actual;
        }
        if (array_is_list($expected) || array_is_list($actual)) {
            if (! array_is_list($expected) || ! array_is_list($actual) || count($expected) !== count($actual)) {
                return false;
            }
        }
        foreach ($expected as $key => $value) {
            if (! array_key_exists($key, $actual) || ! $this->isSubset($value, $actual[$key])) {
                return false;
            }
        }

        return true;
    }

    /** @return array{valid:false,reason:string,terms:list<string>,claims:list<array<string,mixed>>} */
    private function failure(string $reason, array $terms): array
    {
        return ['valid' => false, 'reason' => $reason, 'terms' => $terms, 'claims' => []];
    }
}

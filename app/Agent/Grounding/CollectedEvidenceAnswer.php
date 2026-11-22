<?php

declare(strict_types=1);

namespace App\Agent\Grounding;

use App\Agent\AgentAnswer;
use App\Agent\Evidence\DeclaredRecordCollections;
use App\Services\Chat\QuestionUnderstanding;
use App\Services\Chat\Reasoning\EvidenceProjector;
use App\Services\Kb\Retrieval\QueryTagExtractor;

final readonly class CollectedEvidenceAnswer
{
    public function __construct(private QueryTagExtractor $tags) {}

    /** The caller has reauthorized the current evidence envelope. */
    public function present(string $question, QuestionUnderstanding $intent, array $evidence, string $locale): AgentAnswer
    {
        $italian = str_starts_with($locale, 'it');
        $identifiers = $intent->targetIdentifiers();
        [$rows, $tools] = $this->records($evidence['api_tools'], $identifiers);
        $tasks = $intent->subquestions ?: [$intent->focus];
        $documentTasks = array_filter(array_keys($tasks), fn (int $index): bool => ! $intent->requiresCurrentObservationFor($index));
        [$excerpts, $citations] = $documentTasks === [] ? [[], []]
            : $this->excerpts($evidence['documents'], $question, $identifiers, array_column($rows, 2));
        $sections = [];
        if ($rows !== []) {
            $header = $italian ? '| Riferimento | Articolo | Taglia / variante |' : '| Reference | Item | Size / variant |';
            $sections[] = implode("\n", [$header, '| --- | --- | --- |',
                ...array_map(fn (array $row): string => '| '.implode(' | ', array_map($this->literal(...), $row)).' |', $rows)]);
        }
        if ($excerpts !== []) {
            $heading = $italian ? '**Estratti dalle fonti raccolte**' : '**Excerpts from the collected sources**';
            $sections[] = $heading."\n\n".implode("\n\n", array_map(
                fn (array $excerpt): string => '> '.$this->literal($excerpt['text']), $excerpts));
        }
        $notice = $italian
            ? 'La sintesi non è disponibile. La risposta è parziale e le conclusioni richieste restano da verificare.'
            : 'Synthesis is unavailable. This answer is partial and the requested conclusions still need verification.';
        $sections[] = $notice;

        return new AgentAnswer(implode("\n\n", $sections), $locale, 'partial', $citations, $tools, [$notice],
            grounding: ['status' => 'collected_evidence', 'reason' => 'synthesis_unavailable', 'claims' => [],
                'source_excerpts' => $excerpts, 'recovery' => ['reason' => 'synthesis_unavailable'],
                'coverage_validation' => AnswerCoverageEvaluator::unavailable($question, $intent->subquestions, 'skipped')]);
    }

    private function records(array $observations, array $identifiers): array
    {
        $rows = $sources = [];
        foreach ($observations as $observation) {
            if (! EvidenceProjector::isLiveRecordObservation($observation) || ! is_int($observation['execution_id'] ?? null)) {
                continue;
            }
            foreach (EvidenceProjector::records($observation) as $entry) {
                $record = $entry['record'];
                $identities = EvidenceProjector::identities($record, $observation['presentation']['identity_fields'] ?? []);
                $reference = array_values(array_intersect($identities, $identifiers))[0] ?? null;
                if ($reference === null) {
                    continue;
                }
                foreach (DeclaredRecordCollections::project($record, $observation['presentation']['record_collections'] ?? []) as $collection) {
                    $fields = $collection['fields'];
                    foreach ($collection['rows'] as $row) {
                        $item = $row[$fields['reference'] ?? ''] ?? null;
                        $variant = $row[$fields['variant'] ?? ''] ?? null;
                        if (! is_scalar($item) || ! is_scalar($variant) || trim((string) $item) === '' || trim((string) $variant) === '') {
                            continue;
                        }
                        $values = [$reference, (string) $item, (string) $variant];
                        $key = json_encode($values, JSON_THROW_ON_ERROR);
                        if (isset($rows[$key]) || count($rows) >= 20) {
                            continue;
                        }
                        $rows[$key] = $values;
                        $sources[$observation['execution_id']] = array_intersect_key($observation,
                            array_flip(['execution_id', 'tool', 'display_name', 'kind', 'evidence_hash', 'retrieved_at']));
                    }
                }
            }
        }

        return [array_values($rows), array_values($sources)];
    }

    private function excerpts(array $documents, string $question, array $identifiers, array $variants): array
    {
        $terms = array_map(static fn (string $term): string => mb_substr($term, 0, -1),
            array_filter($this->tags->extract($question, maxTags: 40), static fn (string $term): bool => preg_match('/^\pL{5,}$/u', $term) === 1));
        $asksForConditions = preg_match('/\b(?:condizion\w*|conditions?|prerequisites?)\b/iu', $question) === 1;
        $candidates = [];
        foreach ($documents as $document) {
            foreach ($document['evidence'] ?? [] as $passage) {
                $content = $passage['content'] ?? '';
                $title = $document['title'] ?? '';
                if ($identifiers !== [] && ! array_filter($identifiers, static fn ($id): bool => EvidenceProjector::contains($title."\n".$content, $id))) {
                    continue;
                }
                foreach (preg_split('/\R[> \t]*\R/u', $content) ?: [] as $paragraph) {
                    // Remove nesting markers for display, retaining the exact excerpt in its citation.
                    $text = trim(preg_replace('/^[> \t]+/m', '', $paragraph) ?? '');
                    $text = preg_replace('/\s+/u', ' ', $text) ?? '';
                    if (mb_strlen($text) < 20 || mb_strlen($text) > 480 || preg_match('/https?:\/\/|^\|/i', $text)) {
                        continue;
                    }
                    $lower = mb_strtolower($text);
                    $matches = array_filter($terms, static fn ($term): bool => str_contains($lower, $term));
                    if ($identifiers === [] && count($matches) === 1
                        && ! str_contains(mb_strtolower($title), reset($matches))) {
                        continue;
                    }
                    if ($matches !== []) {
                        $mentionsReference = array_filter($identifiers, static fn ($id): bool => EvidenceProjector::contains($text, $id)) !== [];
                        $categories = [];
                        if ($asksForConditions && preg_match('/(?:^|[,.]\s*)(?:se |qualora |if |provided )|\b(?:a condizione che|subject to)\b/iu', $text)) {
                            $categories[] = 'conditional';
                        }
                        if ($asksForConditions && $mentionsReference && preg_match('/\b(?:prima di|before)\b.*\b(?:conferm\w*|confirm\w*|verific\w*|verify)\b/iu', $text)) {
                            $categories[] = 'confirmation';
                        }
                        if ($mentionsReference && mb_strlen($text) <= 100) {
                            $categories[] = 'short_statement';
                        }
                        if (preg_match('/\b(?:tagli[ae]|sizes?|variants?)\b/iu', $text)
                            && array_filter($variants, static fn ($variant): bool => EvidenceProjector::contains($text, $variant))) {
                            $categories[] = 'item_variant';
                        }
                        $candidates[$lower] ??= ['terms' => $matches, 'quote' => trim($paragraph), 'text' => $text,
                            'categories' => $categories, 'document' => $document, 'chunk' => $passage];
                    }
                }
            }
        }
        $excerpts = $citations = $shown = $categoriesShown = [];
        while ($candidates !== [] && count($excerpts) < 4) {
            $score = static fn ($candidate): int => 10 * count(array_diff($candidate['categories'], $categoriesShown))
                + 2 * count(array_diff($candidate['terms'], $shown)) + count($candidate['terms']);
            uasort($candidates, static fn ($a, $b): int => $score($b) <=> $score($a));
            $key = array_key_first($candidates);
            ['text' => $text, 'quote' => $quote, 'document' => $document, 'chunk' => $chunk,
                'terms' => $matched, 'categories' => $categories] = $candidates[$key];
            unset($candidates[$key]);
            $shown = [...$shown, ...$matched];
            $categoriesShown = [...$categoriesShown, ...$categories];
            $id = $document['document_id'];
            $excerpt = ['kind' => 'source_excerpt', 'business_claim_approved' => false, 'text' => $text, 'quote' => $quote,
                'document_id' => $id, 'evidence_hash' => $chunk['evidence_hash']];
            $excerpts[] = $excerpt;
            if (! isset($citations[$id])) {
                unset($document['evidence']);
                $citations[$id] = [...$document, 'chunks' => [], 'claims' => []];
            }
            $citations[$id]['chunks'][] = ['chunk_id' => $chunk['chunk_id'] ?? null, 'heading' => $chunk['heading'] ?? null,
                'snippet' => $quote, 'evidence_hash' => $chunk['evidence_hash']];
            $citations[$id]['claims'][] = $excerpt;
        }

        return [$excerpts, array_values($citations)];
    }

    private function literal(string $value): string
    {
        return strtr(htmlspecialchars(str_replace(["\r", "\n"], ' ', $value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            ['|' => '&#124;', '[' => '&#91;', ']' => '&#93;', '*' => '&#42;', '`' => '&#96;', '_' => '&#95;', '\\' => '&#92;']);
    }
}

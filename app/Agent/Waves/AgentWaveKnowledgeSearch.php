<?php

declare(strict_types=1);

namespace App\Agent\Waves;

use App\Agent\AgentExecutionContext;
use App\Agent\Evidence\AgentEvidenceFactory;
use App\Agent\Tools\AgentToolActionResult;
use App\Models\AgentRun;
use App\Services\Chat\QuestionUnderstanding;
use App\Services\Chat\Reasoning\EvidenceProjector;
use App\Services\Kb\Chat\ChatRetrievalService;
use App\Services\Kb\Investigation\KbSourceReader;
use App\Services\Kb\KbSearchService;
use App\Services\Kb\Retrieval\RetrievalFilters;
use App\Services\Kb\Retrieval\SearchResult;

final class AgentWaveKnowledgeSearch
{
    public function search(string $query, AgentRun $run, AgentExecutionContext $context, RetrievalFilters $filters): AgentToolActionResult
    {
        $understanding = QuestionUnderstanding::fromArray((array) data_get($run->result_json, 'question_understanding', []));
        $identifiers = $this->identifiersForQuery($query, $understanding);
        $stats = [];
        $exact = collect();
        $semantic = collect();
        $diagnostics = app(\App\Agent\Debug\RunDiagnostics::class)->observer($run);
        try {
            if ($identifiers !== []) {
                $exact = $diagnostics('kb.exact_lookup', fn () => app(KbSearchService::class)->exactIdentifierCandidates($identifiers, $context->projectKey, $filters),
                    ['wait_reason' => 'local_database', 'error_handled' => true]);
                // Prefer the matching chunk over earlier chunks of a title-matched document.
                $exact = $exact->sortBy([
                    ['literal_rank', 'desc'],
                    [fn ($a, $b) => (int) (bool) array_filter($identifiers, fn ($id) => EvidenceProjector::contains((string) data_get($b, 'chunk_text', ''), $id))
                        <=> (int) (bool) array_filter($identifiers, fn ($id) => EvidenceProjector::contains((string) data_get($a, 'chunk_text', ''), $id))],
                    [fn ($a, $b) => (strtotime((string) data_get($b, 'document.source_date', '')) ?: 0)
                        <=> (strtotime((string) data_get($a, 'document.source_date', '')) ?: 0)],
                ])->values();
                $exact = $this->diverseExactCandidates($exact, $identifiers, $query);
            }
        } catch (\Throwable) {
            $stats['exact_lookup_error'] = true;
        }
        $sources = [];
        $provenance = [];
        $alreadyCollected = $this->collectedEvidence($run);
        $limit = max(1, (int) config('kb.investigation.max_sources_per_round', 5));
        $collect = function ($candidates) use (&$sources, &$provenance, &$stats, $alreadyCollected, $limit, $diagnostics, $run, $context, $filters): void {
            foreach ($candidates->unique('chunk_id') as $candidate) {
                $id = (int) data_get($candidate, 'document.id', 0);
                if ($id < 1) {
                    continue;
                }
                try {
                    $source = $diagnostics('kb.source_read', fn () => app(KbSourceReader::class)->readCandidate($candidate, $context->projectKey, $run->user, $filters, $diagnostics),
                        ['wait_reason' => 'local_source_read', 'error_handled' => true]);
                } catch (\Throwable) {
                    $stats['source_read_error'] = true;
                    continue;
                }
                if ($source === null) {
                    continue;
                }
                $candidate['chunk_text'] = $source['excerpt'];
                $candidate['chunk_hash'] = hash('sha256', $source['excerpt']);
                $key = $id.':'.$candidate['chunk_hash'];
                if (isset($sources[$key]) || isset($alreadyCollected[$key])) {
                    continue;
                }
                $candidate['document'] = array_merge((array) ($candidate['document'] ?? []), [
                    'id' => $source['document_id'], 'title' => $source['title'],
                    'source_path' => $source['source_path'], 'source_type' => $source['source_type'],
                ]);
                $candidate['metadata'] = array_merge((array) ($candidate['metadata'] ?? []), [
                    'investigation' => ['complete_source' => $source['complete_source'], 'chunk_ids' => $source['chunk_ids'],
                        'evidence_origin' => $source['evidence_origin'] ?? null],
                ]);
                $sources[$key] = $candidate;
                $provenance[$key] = ['complete_source' => $source['complete_source'],
                    'source_chunk_ids' => $source['chunk_ids'], 'evidence_origin' => $source['evidence_origin'] ?? null];
                if (count($sources) >= $limit) {
                    break;
                }
            }
        };
        // Revalidate exact candidates first. When they fill this bounded result,
        // no semantic candidate could be selected: do not wait for unused embeddings.
        $collect($exact);
        $semanticSkipped = count($sources) >= $limit;
        if (! $semanticSkipped) {
            try {
                $retrieve = fn () => app(ChatRetrievalService::class)->retrieve($query, $context->projectKey, $filters->withoutExpansion(), false, $diagnostics)->primary;
                $semantic = $exact->isNotEmpty()
                    ? \App\Ai\EmbeddingTimeoutScope::within(max(1, (int) config('agent.waves.exact_embedding_timeout_seconds', 10)), $retrieve)
                    : $retrieve();
            } catch (\Throwable) {
                $stats['semantic_lookup_error'] = true;
            }
            $collect($semantic);
        }
        $search = new SearchResult(collect(array_values($sources)), collect(), collect());
        $documents = app(AgentEvidenceFactory::class)->fromSearchResult($search)->documents();
        foreach ($documents as &$document) {
            foreach ($document['evidence'] as &$evidence) {
                $key = $document['document_id'].':'.$evidence['evidence_hash'];
                $evidence = [...$evidence, ...($provenance[$key] ?? [])];
            }
            unset($evidence);
        }
        unset($document);
        $partial = $stats !== [];
        return new AgentToolActionResult(
            ['documents' => $documents],
            0,
            ! $partial,
            $partial ? 'retrieval_partial' : null,
            [...$stats, 'exact_candidates' => $exact->count(), 'semantic_candidates' => $semantic->count(),
                'semantic_skipped_full_exact' => $semanticSkipped, 'selected_sources' => count($sources)],
        );
    }

    /** Latest updates and a query topic anchor, then distinct documents before extra windows. */
    private function diverseExactCandidates(\Illuminate\Support\Collection $candidates, array $identifiers, string $query): \Illuminate\Support\Collection
    {
        $updates = [];
        foreach ($identifiers as $identifier) {
            $dated = $candidates->filter(fn ($candidate) => strtotime((string) data_get($candidate, 'document.source_date', '')) !== false
                && (EvidenceProjector::contains((string) data_get($candidate, 'document.title', ''), $identifier)
                    || EvidenceProjector::contains((string) data_get($candidate, 'chunk_text', ''), $identifier)));
            $latest = $dated->sortByDesc(fn ($candidate) => strtotime((string) data_get($candidate, 'document.source_date', '')))->first();
            if ($latest !== null) {
                // In a dated email the opening matching window contains the
                // update; a quoted old reply can repeat more identifiers later.
                $updates[] = $dated->filter(fn ($candidate) => data_get($candidate, 'document.id') === data_get($latest, 'document.id'))
                    ->sortBy('chunk_id')->first();
            }
        }
        $topical = $this->topicalCandidate($candidates, $query, $identifiers);
        $priority = collect([...$updates, ...($topical === null ? [] : [$topical])])->unique('chunk_id')->values();
        $first = $extra = [];
        $seen = array_fill_keys($priority->pluck('document.id')->all(), true);
        $used = array_fill_keys($priority->pluck('chunk_id')->all(), true);
        foreach ($candidates->unique('chunk_id') as $candidate) {
            if (isset($used[$candidate['chunk_id']])) continue;
            $id = (int) data_get($candidate, 'document.id', 0);
            if (isset($seen[$id])) $extra[] = $candidate;
            else { $first[] = $candidate; $seen[$id] = true; }
        }
        return $priority->concat([...$first, ...$extra])->values();
    }

    /** Lexical navigation inside exact subjects; this never approves a fact. */
    private function topicalCandidate(\Illuminate\Support\Collection $candidates, string $query, array $identifiers): ?array
    {
        preg_match_all('/[\pL\pN_-]{3,}/u', mb_strtolower($query), $queryTerms);
        preg_match_all('/[\pL\pN_-]{3,}/u', mb_strtolower(implode(' ', $identifiers)), $subjectTerms);
        $terms = array_values(array_diff(array_unique($queryTerms[0]), $subjectTerms[0]));
        if ($terms === [] || $candidates->isEmpty()) return null;
        $matches = $frequencies = [];
        $documentCount = max(1, $candidates->pluck('document.id')->unique()->count());
        foreach ($candidates as $candidate) {
            $text = (string) data_get($candidate, 'document.title', '')."\n".($candidate['chunk_text'] ?? '');
            foreach ($terms as $term) {
                if (! EvidenceProjector::contains($text, $term)) continue;
                $matches[$candidate['chunk_id']][] = $term;
                $frequencies[$term][data_get($candidate, 'document.id')] = true;
            }
        }
        $best = null;
        $bestScore = 0;
        foreach ($candidates as $candidate) {
            $score = 0;
            foreach ($matches[$candidate['chunk_id']] ?? [] as $term) {
                $frequency = count($frequencies[$term]);
                // Common connective words contribute no extra navigation when
                // several documents are available. No domain vocabulary list.
                if ($documentCount > 2 && $frequency > $documentCount / 2) continue;
                $score += log(1 + $documentCount / $frequency);
            }
            if ($score > $bestScore) { $best = $candidate; $bestScore = $score; }
        }
        return $best;
    }

    /** @return array<string, true> */
    private function collectedEvidence(AgentRun $run): array
    {
        $collected = [];
        foreach ((array) data_get($run->result_json, 'evidence.documents', []) as $document) {
            $id = $document['document_id'] ?? null;
            foreach ((array) ($document['evidence'] ?? []) as $evidence) {
                $hash = $evidence['evidence_hash'] ?? null;
                if (is_int($id) && is_string($hash) && $hash !== '') {
                    $collected[$id.':'.$hash] = true;
                }
            }
        }
        return $collected;
    }

    /** Select only previously understood subjects belonging to this search. */
    private function identifiersForQuery(string $query, QuestionUnderstanding $understanding): array
    {
        if (! $understanding->available) {
            return [];
        }
        // The source/system name may be a literal mention without being the
        // subject (for example "ORDER-A in Gescat"). Do not turn it into a
        // corpus-wide exact anchor when the interpreter supplied precise targets.
        $targets = $understanding->targetIdentifiers();
        $known = array_values(array_filter(array_unique($targets !== [] ? $targets : $understanding->mentionTexts()), 'is_string'));
        $explicit = array_values(array_filter($known, fn ($id) => EvidenceProjector::contains($query, $id)));
        if ($explicit !== []) {
            return $explicit;
        }

        $matched = [];
        foreach ($understanding->subquestions as $subquestion) {
            foreach (($subquestion['kb_queries'] ?? []) as $seed) {
                if (is_string($seed) && mb_strtolower(trim($seed)) === mb_strtolower(trim($query))) {
                    $matched = [...$matched, ...($subquestion['identifiers'] ?? [])];
                    break;
                }
            }
        }
        if ($matched !== []) {
            return array_values(array_intersect($known, array_unique($matched)));
        }
        if (count($understanding->subquestions) <= 1) {
            $targets = $understanding->targetIdentifiers();
            if (count($targets) === 1 && is_string($targets[0])) {
                return $targets;
            }
        }
        return [];
    }
}

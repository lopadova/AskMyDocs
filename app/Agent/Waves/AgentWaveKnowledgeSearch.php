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
                $exact = $exact->sortByDesc(fn ($candidate) => (int) (bool) array_filter($identifiers,
                    fn ($id) => EvidenceProjector::contains((string) data_get($candidate, 'chunk_text', ''), $id)))->values();
            }
        } catch (\Throwable) {
            $stats['exact_lookup_error'] = true;
        }
        try {
            $semantic = app(ChatRetrievalService::class)->retrieve($query, $context->projectKey, $filters, false, $diagnostics)->primary;
        } catch (\Throwable) {
            $stats['semantic_lookup_error'] = true;
        }
        $sources = [];
        $provenance = [];
        $alreadyCollected = $this->collectedEvidence($run);
        $limit = max(1, (int) config('kb.investigation.max_sources_per_round', 5));
        foreach ($exact->concat($semantic)->unique('chunk_id') as $candidate) {
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
            [...$stats, 'exact_candidates' => $exact->count(), 'semantic_candidates' => $semantic->count(), 'selected_sources' => count($sources)],
        );
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
        $known = array_values(array_filter(array_unique(array_merge(
            $understanding->targetIdentifiers(), $understanding->mentionTexts(),
        )), 'is_string'));
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

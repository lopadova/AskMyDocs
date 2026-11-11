<?php

declare(strict_types=1);

namespace App\Agent\Evidence;

use App\Services\Kb\Chat\ChatRetrievalService;
use App\Services\Kb\Retrieval\SearchResult;
use App\Services\Widget\WidgetPiiMasker;

final readonly class AgentEvidenceFactory
{
    public function __construct(
        private ChatRetrievalService $retrieval,
        private WidgetPiiMasker $masker,
    ) {}

    public function empty(): AgentEvidenceEnvelope
    {
        return new AgentEvidenceEnvelope($this->masker);
    }

    public function fromSearchResult(SearchResult $result): AgentEvidenceEnvelope
    {
        $envelope = $this->empty();
        $fullTextByHash = [];
        $knowledgeByHash = [];
        $acquiredByHash = [];
        $flowIdsByHash = [];
        foreach ([$result->primary, $result->expanded, $result->rejected] as $chunks) {
            foreach ($chunks as $chunk) {
                $text = (string) data_get($chunk, 'chunk_text', '');
                $hash = (string) (data_get($chunk, 'chunk_hash')
                    ?? data_get($chunk, 'metadata.chunk_hash')
                    ?? hash('sha256', $text));
                $knowledgeByHash[(string) data_get($chunk, 'document.id').':'.$hash] = array_filter([
                    'knowledge_observation_id' => data_get($chunk, 'knowledge_observation_id'),
                    'knowledge_section_ordinal' => data_get($chunk, 'knowledge_section_ordinal'),
                    'coverage' => data_get($chunk, 'metadata.knowledge.coverage') ?? data_get($chunk, 'metadata.investigation.coverage'),
                ], static fn ($v) => $v !== null);
                $fullTextByHash[$hash] = $text;
                $acquiredByHash[$hash] = data_get($chunk, 'retrieved_at') ?? now()->toIso8601String();
                if (isset($chunk['research_flow_id'])) {
                    $flowIdsByHash[$hash][] = $chunk['research_flow_id'];
                }
            }
        }

        foreach ($this->retrieval->buildCitations($result) as $citation) {
            $citation['evidence'] = array_map(static function (array $chunk) use ($fullTextByHash, $flowIdsByHash, $acquiredByHash, $knowledgeByHash, $citation): array {
                $hash = (string) ($chunk['evidence_hash'] ?? '');

                return [
                    ...($knowledgeByHash[(string) ($citation['document_id'] ?? '').':'.$hash] ?? []),
                    'chunk_id' => $chunk['chunk_id'] ?? null,
                    'heading' => $chunk['heading'] ?? null,
                    'score' => (float) ($chunk['score'] ?? 0),
                    'content' => $fullTextByHash[$hash] ?? (string) ($chunk['snippet'] ?? ''),
                    'evidence_hash' => $hash !== '' ? $hash : null,
                    'retrieved_at' => $acquiredByHash[$hash] ?? now()->toIso8601String(),
                    ...isset($flowIdsByHash[$hash]) ? ['research_flow_ids' => array_values(array_unique($flowIdsByHash[$hash]))] : [],
                ];
            }, is_array($citation['chunks'] ?? null) ? $citation['chunks'] : []);
            unset($citation['chunks']);
            $envelope->addDocument($citation);
        }

        return $envelope;
    }
}

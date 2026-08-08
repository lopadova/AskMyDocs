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
        foreach ([$result->primary, $result->expanded, $result->rejected] as $chunks) {
            foreach ($chunks as $chunk) {
                $text = (string) data_get($chunk, 'chunk_text', '');
                $hash = (string) (data_get($chunk, 'chunk_hash')
                    ?? data_get($chunk, 'metadata.chunk_hash')
                    ?? hash('sha256', $text));
                $fullTextByHash[$hash] = $text;
            }
        }

        foreach ($this->retrieval->buildCitations($result) as $citation) {
            $citation['evidence'] = array_map(static function (array $chunk) use ($fullTextByHash): array {
                $hash = (string) ($chunk['evidence_hash'] ?? '');

                return [
                    'chunk_id' => $chunk['chunk_id'] ?? null,
                    'heading' => $chunk['heading'] ?? null,
                    'score' => (float) ($chunk['score'] ?? 0),
                    'content' => $fullTextByHash[$hash] ?? (string) ($chunk['snippet'] ?? ''),
                    'evidence_hash' => $hash !== '' ? $hash : null,
                ];
            }, is_array($citation['chunks'] ?? null) ? $citation['chunks'] : []);
            unset($citation['chunks']);
            $envelope->addDocument($citation);
        }

        return $envelope;
    }
}

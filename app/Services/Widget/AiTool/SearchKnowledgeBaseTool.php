<?php

declare(strict_types=1);

namespace App\Services\Widget\AiTool;

use App\Models\WidgetSession;
use App\Services\Kb\Chat\ChatRetrievalService;

/**
 * SearchKnowledgeBaseTool — tool BE built-in: esegue retrieval RAG e ritorna
 * risultati come artifact `ui-data-table` (spec §5.3).
 *
 * R23: implementa WidgetAiToolInterface. Il registry valida FQCN + supports() mutex.
 * Se la query è vuota o non ha risultati, ritorna has_results=false con un
 * artifact ui-alert (il FE manderà un auto-msg al LLM).
 */
final class SearchKnowledgeBaseTool implements WidgetAiToolInterface
{
    public function __construct(
        private readonly ChatRetrievalService $retrieval,
    ) {}

    public function toolName(): string
    {
        return 'search_knowledge_base';
    }

    public function description(): string
    {
        return 'Cerca nella knowledge base del progetto e ritorna i documenti rilevanti come artifact. '
            . 'Usa questo tool quando l\'utente chiede informazioni che potrebbero essere nella '
            . 'documentazione o nel knowledge base.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => (object) [
                'query' => ['type' => 'string', 'description' => 'Termine o frase da cercare nella knowledge base.'],
            ],
            'additionalProperties' => false,
            'required' => ['query'],
        ];
    }

    public function supports(array $aiTools, array $toolsEnabled, bool $isBuiltin): bool
    {
        // I built-in sono abilitati se toolName() appare in tools_enabled
        if ($isBuiltin) {
            return in_array($this->toolName(), $toolsEnabled, true);
        }

        // I tool custom sono abilitati se toolName() appare in ai_tools
        return in_array($this->toolName(), $aiTools, true);
    }

    public function execute(array $args, WidgetSession $session): array
    {
        $query = (string) ($args['query'] ?? '');

        if ($query === '') {
            return [
                'artifact' => [
                    'componentType' => 'ui-alert',
                    'componentProps' => [
                        'level' => 'warning',
                        'title' => 'Query vuota',
                        'message' => 'Inserisci un termine di ricerca nella knowledge base.',
                    ],
                ],
                'has_results' => false,
                'interaction_mode' => 'view',
            ];
        }

        $result = $this->retrieval->retrieve($query, (string) $session->project_key, null);

        // #4 — Stesso grounding gate delle altre chat channel: chunk presenti ma
        // sotto-floor ⇒ "nessun risultato pertinente", non una data-table di
        // documenti non groundati (refusal-bypass v8.1 anche sul path BE-tool).
        if ($result->primary->isEmpty() || $this->retrieval->shouldRefuse($result)) {
            return [
                'artifact' => [
                    'componentType' => 'ui-alert',
                    'componentProps' => [
                        'level' => 'info',
                        'title' => 'Nessun risultato',
                        'message' => "Nessun documento trovato per \"{$query}\".",
                    ],
                ],
                'has_results' => false,
                'interaction_mode' => 'view',
            ];
        }

        // I chunk della retrieval sono ARRAY in produzione (KbSearchService::search
        // mappa ad array; ChatRetrievalService: "chunks are arrays in production").
        // Letture shape-agnostic via data_get — leggere $chunk->id su un array
        // lancia "Attempt to read property on array" → 500 (era il bug class v8.1
        // mascherato da un fixture (object) nei test, R13/R16).
        $rows = $result->primary->map(fn ($chunk) => [
            'id' => (string) (data_get($chunk, 'chunk_id') ?? data_get($chunk, 'id') ?? ''),
            'title' => (string) (data_get($chunk, 'document.title') ?? data_get($chunk, 'heading_path') ?? 'Documento'),
            'similarity' => round((float) (data_get($chunk, 'rerank_score') ?? data_get($chunk, 'vector_score') ?? 0), 3),
            'content_preview' => mb_substr((string) data_get($chunk, 'chunk_text', ''), 0, 200),
        ])->values()->toArray();

        $columns = [
            ['key' => 'title', 'label' => 'Titolo'],
            ['key' => 'similarity', 'label' => 'Rilevanza'],
            ['key' => 'content_preview', 'label' => 'Anteprima'],
        ];

        return [
            'artifact' => [
                'componentType' => 'ui-data-table',
                'componentProps' => [
                    'columns' => $columns,
                    'rows' => $rows,
                    'rowKey' => 'id',
                    'interactionMode' => 'selection',
                    'title' => "Risultati per \"{$query}\"",
                ],
            ],
            'has_results' => true,
            'interaction_mode' => 'selection',
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Chat\Reasoning;

use App\Models\AgentRun;
use App\Models\Conversation;
use App\Services\Chat\QuestionUnderstanding;
use App\Services\Kb\Investigation\KbSourceReader;
use App\Services\Kb\Retrieval\RetrievalFilters;
use App\Services\Widget\WidgetPiiMasker;
use App\Support\TenantContext;

/** Navigation selects candidates; only a fresh authorized source read supplies evidence. */
final readonly class ConversationSourceEvidence
{
    public function __construct(private ConversationReasoning $memory, private KbSourceReader $reader,
        private WidgetPiiMasker $masker) {}

    public function forRun(AgentRun $run, QuestionUnderstanding $understanding, RetrievalFilters $filters, int $availableBytes): array
    {
        $result = ['documents' => [], 'api_tools' => [], 'warnings' => []];
        if (! $this->memory->enabled() || ! $understanding->available || ! $understanding->referencesPreviousTurn
            || $understanding->needsClarification || $understanding->asksForProvenance() || $understanding->asksToReadSource()) return $result;
        $owner = $this->memory->runOwner($run);
        if ($owner === null) return $result;
        if ((string) $run->tenant_id !== (string) app(TenantContext::class)->current()
            || (string) $owner->tenant_id !== (string) $run->tenant_id || $owner->project_key !== $run->project_key
            || ($owner instanceof Conversation && $owner->user_id != $run->user_id)) {
            throw new \DomainException('conversation_source_scope_mismatch');
        }
        $tasks = array_filter($understanding->subquestions, fn ($task) => ($task['context_mode'] ?? '') === 'conversation'
            && ($task['identifiers'] ?? []) !== []);
        if ($tasks === []) return $result;
        $bytes = 0;
        $seen = [];
        foreach ($this->memory->state($owner)['evidence'] as $reference) {
            if (($reference['kind'] ?? '') !== 'document') continue;
            $source = $this->reader->readCandidate(['document' => ['id' => $reference['document_id'] ?? 0],
                'chunk_id' => $reference['chunk_id'] ?? 0], $run->project_key, $run->user, $filters);
            if ($source === null || trim($source['excerpt'] ?? '') === '') continue;
            $identityText = $source['title']."\n".$source['excerpt'];
            $flows = array_keys(array_filter($tasks, static function ($task) use ($identityText): bool {
                $ids = $task['identifiers'];
                $opaque = array_filter($ids, fn ($id) => preg_match('/^[\pL\pN_-]+$/u', $id) && preg_match('/[\pN_-]/u', $id));
                return (bool) array_filter($opaque ?: $ids, fn ($id) => EvidenceProjector::contains($identityText, $id));
            }));
            if ($flows === []) continue;
            $content = $this->masker->maskString($source['excerpt']);
            $hash = hash('sha256', $content);
            $key = $source['document_id'].':'.$hash;
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $identifiers = array_values(array_filter($understanding->targetIdentifiers(), fn ($id) => EvidenceProjector::contains($identityText, $id)));
            $document = ['document_id' => $source['document_id'], 'title' => $this->masker->maskString($source['title']),
                'project_key' => $run->project_key, 'source_path' => $source['source_path'], 'source_type' => $source['source_type'],
                'origin' => 'primary', 'conversation_reference_reread' => true, 'evidence' => [[
                    'chunk_id' => (int) $reference['chunk_id'], 'source_chunk_ids' => $source['chunk_ids'] ?? [],
                    'content' => $content, 'evidence_hash' => $hash, 'retrieved_at' => $source['retrieved_at'],
                    'source_title' => $this->masker->maskString($source['title']),
                    'complete_source' => (bool) ($source['complete_source'] ?? false),
                    'evidence_origin' => $source['evidence_origin'] ?? null, 'research_flow_ids' => $flows,
                    'verified_identifier_hashes' => array_map(fn ($id) => hash('sha256', $id), $identifiers),
                ]]];
            $size = strlen(json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            if ($bytes + $size > max(0, $availableBytes)) {
                $result['warnings'][] = ['code' => 'conversation_source_evidence_limit', 'source' => 'document:'.$source['document_id']];
                continue; // Never truncate a passage or treat a bounded subset as an absence proof.
            }
            $bytes += $size;
            $result['documents'][] = $document;
        }
        return $result;
    }
}

<?php

declare(strict_types=1);

namespace App\Agent\Evidence;

use App\Agent\AgentExecutionContext;
use App\Agent\AgentRetrievalFiltersFactory;
use App\Agent\Tools\AgentLiveSourceSelection;
use App\Agent\Tools\AgentToolRegistry;
use App\Models\AgentRun;
use App\Models\AgentToolExecution;
use App\Services\Kb\Investigation\KbSourceReader;
use App\Services\Widget\WidgetPiiMasker;

/** Revalidate persisted observations before a resumed run can send them to any model. */
final readonly class AgentEvidenceAccess
{
    public function __construct(private KbSourceReader $reader, private AgentToolRegistry $registry,
        private AgentRetrievalFiltersFactory $filters, private AgentLiveSourceSelection $selection,
        private WidgetPiiMasker $masker) {}

    public function current(AgentRun $run, AgentExecutionContext $context, array $evidence, bool $historicalRecap = false): array
    {
        $context->assertMatches($run);
        $filters = $this->filters->forRun($run, $context);
        $allowed = $this->selection->apply($this->registry->forContext($context, $run->user), data_get($run->input_json, 'live_sources'));
        $safe = ['documents' => [], 'api_tools' => [], 'warnings' => $evidence['warnings'] ?? []];
        foreach ($evidence['documents'] ?? [] as $document) {
            $chunks = [];
            foreach ($document['evidence'] ?? [] as $chunk) {
                $source = $this->reader->readCandidate(['document' => ['id' => $document['document_id'] ?? 0],
                    'chunk_id' => $chunk['chunk_id'] ?? 0], $context->projectKey, $run->user, $filters);
                $current = $source === null ? '' : $this->masker->maskString($source['excerpt']);
                $original = $chunk['content'] ?? '';
                // A changed or revoked passage loses its old verification; never silently rebind its hash.
                if ($current !== '' && is_string($original) && $original !== '' && str_contains($current, $original)) {
                    $chunks[] = $chunk;
                } else {
                    $safe['warnings'][] = ['code' => 'source_changed_or_revoked', 'source' => 'document'];
                }
            }
            if ($chunks !== []) {
                $safe['documents'][] = [...$document, 'evidence' => $chunks, 'chunks_used' => count($chunks)];
            }
        }
        foreach ($evidence['api_tools'] ?? [] as $tool) {
            $execution = AgentToolExecution::query()->find($tool['execution_id'] ?? 0);
            $sourceRun = $execution?->run;
            $sameConversation = $sourceRun !== null && $sourceRun->tenant_id === $run->tenant_id
                && $sourceRun->project_key === $run->project_key && $sourceRun->user_id === $run->user_id
                && ($run->conversation_id !== null ? $sourceRun->conversation_id === $run->conversation_id
                    : ($run->widget_session_id !== null && $sourceRun->widget_session_id === $run->widget_session_id));
            $definition = $allowed[$tool['tool'] ?? ''] ?? null;
            $researchChild = $sourceRun !== null
                && in_array($sourceRun->id, data_get($run->result_json, 'research_runs', []), true)
                && data_get($sourceRun->input_json, 'research_parent_id') === $run->id
                && $sourceRun->actor_type === $run->actor_type && $sourceRun->actor_id === $run->actor_id;
            if ($definition !== null && $execution !== null && $execution->status === 'completed'
                && $execution->tool_name === $tool['tool'] && $sameConversation
                && ($sourceRun->id === $run->id || $researchChild || $historicalRecap)
                && ($definition->kind !== 'catalog' || $this->catalogAuthorized($run, $tool))
                && ($tool['executor_reference'] ?? null) === $definition->executorReference
                && hash_equals((string) ($tool['evidence_hash'] ?? ''), hash('sha256', (string) json_encode($tool['result'] ?? [], JSON_UNESCAPED_UNICODE)))) {
                $safe['api_tools'][] = $tool;
            } else {
                $safe['warnings'][] = ['code' => 'live_source_unavailable', 'source' => $tool['tool'] ?? null];
            }
        }
        return $safe;
    }

    /** A KB catalog result is not exempt from document ACLs just because it is shaped like a tool. */
    private function catalogAuthorized(AgentRun $run, array $tool): bool
    {
        foreach (\App\Services\Chat\Reasoning\EvidenceProjector::records($tool) as $entry) {
            $record = $entry['record'];
            $id = $record['document_id'] ?? $record['id'] ?? null;
            if (! is_numeric($id)) {
                return false;
            }
            $document = \App\Models\KnowledgeDocument::query()->forTenant($run->tenant_id)->find((int) $id);
            if ($document === null || $document->status === 'archived'
                || ($run->project_key !== null && $document->project_key !== $run->project_key)
                || ($run->user !== null && ! $run->user->hasDocumentAccess($document))
                || ($run->user === null && $document->source_acl_enforced_at !== null)
                || (isset($record['title']) && $record['title'] !== $this->masker->maskString((string) $document->title))) {
                return false;
            }
        }
        return true;
    }
}

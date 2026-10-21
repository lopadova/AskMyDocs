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
        $identifiers = \App\Services\Chat\QuestionUnderstanding::fromArray(data_get($run->result_json, 'question_understanding', []))->targetIdentifiers();
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
                    // Match exact request tokens BEFORE PII masking, against the
                    // current authorized source and title. Persist only hashes;
                    // distinct order numbers must not collapse to one [PHONE].
                    $identityText = $source['title']."\n".$source['excerpt'];
                    $matches = array_values(array_filter($identifiers,
                        fn ($id) => \App\Services\Chat\Reasoning\EvidenceProjector::contains($identityText, $id)));
                    $chunks[] = [...$chunk, 'source_title' => $this->masker->maskString($source['title']),
                        'verified_identifier_hashes' => array_map(fn ($id) => hash('sha256', $id), $matches),
                        'complete_source' => ($source['complete_source'] ?? false) && trim($current) === trim($original)];
                    if (($source['complete_source'] ?? false) && $current !== $original) {
                        // A fresh full read is separate evidence, never a new
                        // interpretation of the persisted excerpt's old hash.
                        $chunks[] = [...end($chunks), 'content' => $current,
                            'evidence_hash' => hash('sha256', $current), 'complete_source' => true];
                    }
                } elseif ($current !== '' && ($source['evidence_origin'] ?? null) === 'verified_email_original') {
                    // The reader has checked the immutable email's original hash.
                    // An indexed/card excerpt may differ from that original. Discard
                    // its old hash and facts; authorize only this freshly read text.
                    $identityText = $source['title']."\n".$source['excerpt'];
                    $matches = array_values(array_filter($identifiers,
                        fn ($id) => \App\Services\Chat\Reasoning\EvidenceProjector::contains($identityText, $id)));
                    $chunks[] = [...array_intersect_key($chunk, array_flip(['chunk_id', 'research_flow_ids'])),
                        'content' => $current, 'evidence_hash' => hash('sha256', $current),
                        'source_title' => $this->masker->maskString($source['title']),
                        'verified_identifier_hashes' => array_map(fn ($id) => hash('sha256', $id), $matches),
                        'complete_source' => (bool) ($source['complete_source'] ?? false)];
                    $safe['warnings'][] = ['code' => 'verified_email_original_refreshed', 'source' => 'document'];
                } else {
                    $safe['warnings'][] = ['code' => 'source_changed_or_revoked', 'source' => 'document'];
                }
            }
            if ($chunks !== []) {
                $envelope = new AgentEvidenceEnvelope($this->masker);
                $envelope->addDocument([...$document, 'evidence' => $chunks]);
                $chunks = $envelope->documents()[0]['evidence'];
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
                && ($sourceRun->id === $run->id || $researchChild || $historicalRecap || $this->contextTaskAllows($run, $tool))
                && ($definition->kind !== 'catalog' || $this->catalogAuthorized($run, $tool))
                && ($tool['executor_reference'] ?? null) === $definition->executorReference
                && hash_equals((string) ($tool['evidence_hash'] ?? ''), hash('sha256', (string) json_encode($tool['result'] ?? [], JSON_UNESCAPED_UNICODE)))) {
                $safe['api_tools'][] = $sourceRun->id !== $run->id && ! $researchChild
                    ? [...$tool, 'historical_context' => true] : $tool;
            } else {
                $safe['warnings'][] = ['code' => 'live_source_unavailable', 'source' => $tool['tool'] ?? null];
            }
        }
        return $safe;
    }

    private function contextTaskAllows(AgentRun $run, array $tool): bool
    {
        $tasks = \App\Services\Chat\QuestionUnderstanding::fromArray(data_get($run->result_json, 'question_understanding', []))->contextOnlyTasks();
        $identities = array_merge([], ...array_map(fn ($entry) => \App\Services\Chat\Reasoning\EvidenceProjector::identities($entry['record']),
            \App\Services\Chat\Reasoning\EvidenceProjector::records($tool)));
        foreach ($tasks as $task) {
            if (array_intersect($task['identifiers'] ?? [], $identities) !== []) {
                return true;
            }
        }
        return false;
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

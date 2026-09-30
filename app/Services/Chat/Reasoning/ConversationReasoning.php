<?php

declare(strict_types=1);

namespace App\Services\Chat\Reasoning;

use App\Agent\AgentExecutionContext;
use App\Agent\Tools\AgentToolRegistry;
use App\Models\AgentRun;
use App\Models\Conversation;
use App\Models\User;
use App\Models\WidgetSession;
use App\Models\WidgetSessionStep;
use App\Services\Chat\ChatQuestionPreprocessor;
use App\Services\Chat\QuestionUnderstanding;
use App\Services\Kb\Investigation\KbSourceReader;
use App\Services\Kb\Retrieval\RetrievalFilters;
use App\Services\Widget\WidgetPiiMasker;
use App\Support\SensitivePayloadRedactor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Server-owned, bounded conversation memory. Stored references are never authorization. */
final readonly class ConversationReasoning
{
    public function __construct(private ChatQuestionPreprocessor $preprocessor, private KbSourceReader $reader,
        private WidgetPiiMasker $masker, private SensitivePayloadRedactor $redactor) {}

    public function enabled(): bool
    {
        return (bool) config('reasoning.enabled', true);
    }

    public function runOwner(AgentRun $run): ?Model
    {
        return $run->conversation_id !== null ? $run->conversation : WidgetSession::query()->forTenant($run->tenant_id)->find($run->widget_session_id);
    }

    public function runTurn(AgentRun $run): ?Model
    {
        $owner = $this->runOwner($run);
        return $owner instanceof Conversation
            ? $owner->messages()->find(data_get($run->input_json, 'user_message_id'))
            : ($owner instanceof WidgetSession ? $owner->steps()->find(data_get($run->input_json, 'widget_user_step_id')) : null);
    }

    public function state(Model $owner): array
    {
        return (is_array($owner->reasoning_state) ? $owner->reasoning_state : []) + [
            'schema_version' => 1, 'revision' => 0, 'focus_turn' => 0, 'focus' => [], 'subquestions' => [],
            'entities' => [], 'evidence' => [], 'communicated' => [], 'finished_turns' => [], 'issues' => [],
            'objective' => null, 'language' => null,
        ];
    }

    public function prepare(Model $owner, Model $turn, string $question, ?array $profile = null, ?User $actor = null, ?RetrievalFilters $filters = null): QuestionUnderstanding
    {
        $this->assertOwner($owner, $turn);
        if (! $this->enabled()) {
            return $this->preprocessor->interpret($question, null, $profile);
        }
        // Only retries for the SAME turn share a lock. Never hold a DB transaction during an LLM call.
        return Cache::lock('reasoning:'.$turn->getTable().':'.$turn->getKey(), 120)->block(10, function () use ($owner, $turn, $question, $profile, $actor, $filters) {
            $column = $this->metadataColumn($turn);
            $turn->refresh();
            $cached = data_get($turn->{$column}, 'reasoning.understanding');
            if (is_array($cached)) {
                return QuestionUnderstanding::fromArray($cached);
            }
            $owner->refresh();
            $before = $this->context($owner, $actor, $filters, (int) $turn->getKey());
            if ($this->state($owner)['focus_turn'] > $turn->getKey()) {
                // An older in-flight message must not resolve pronouns using a later user's focus.
                $before = ['focus' => [], 'known_identifiers' => [], 'verified_relations' => [], 'references' => []];
            }
            $understanding = $this->preprocessor->interpret($question, null, $profile, $before);
            DB::transaction(function () use ($owner, $turn, $column, $before, $understanding): void {
                $locked = $owner->newQuery()->whereKey($owner->getKey())->lockForUpdate()->firstOrFail();
                $state = $this->state($locked);
                // Late completion/retry cannot take the focus away from a newer user message.
                if ($understanding->available && ! $understanding->asksForProvenance() && $turn->getKey() >= $state['focus_turn']) {
                    foreach ($state['subquestions'] as &$sub) {
                        if (($sub['status'] ?? '') === 'open') {
                            $sub['status'] = 'suspended';
                        }
                    }
                    unset($sub);
                    foreach ($understanding->subquestions as $sub) {
                        $id = hash('sha256', json_encode($sub));
                        $state['subquestions'][$id] = [...$sub, 'status' => $understanding->needsClarification ? 'clarify' : 'open'];
                    }
                    foreach ($understanding->mentions as $mention) {
                        $state['entities'][$mention['text']] = ['identifier' => $mention['text'], 'origin' => 'user', 'turn' => $turn->getKey()];
                    }
                    $state['focus'] = $understanding->focus;
                    $state['objective'] = $understanding->intent;
                    $state['language'] = $understanding->language ?? $state['language'];
                    $state['focus_turn'] = $turn->getKey();
                    if ($understanding->needsClarification) {
                        $state['issues']['turn:'.$turn->getKey()] = ['kind' => 'ambiguity', 'text' => $understanding->clarification, 'status' => 'open'];
                    }
                    $state['revision']++;
                    $locked->forceFill(['reasoning_state' => $this->bounded($state)])->save();
                }
                $turn->forceFill([$column => array_merge((array) $turn->{$column}, ['reasoning' => [
                    'before' => $before, 'understanding' => $understanding->toArray(), 'revision' => $state['revision'],
                ]])])->save();
            });
            $owner->refresh();
            return $understanding;
        });
    }

    /** Context contains identifiers and provenance, never prior assistant prose or stale live values. */
    public function context(Model $owner, ?User $actor = null, ?RetrievalFilters $filters = null, ?int $beforeTurn = null): array
    {
        if ((string) $owner->tenant_id !== (string) app(\App\Support\TenantContext::class)->current()
            || ($owner instanceof Conversation && $actor !== null && $owner->user_id != $actor->id)) {
            throw new \DomainException('reasoning_context_scope_mismatch');
        }
        $state = $this->state($owner);
        $known = array_keys($state['entities']);
        $relations = $references = $validDocumentHashes = [];
        foreach ($state['evidence'] as $reference) {
            if (! $this->authorized($owner, $reference, $actor, $filters)) {
                continue;
            }
            $known = array_merge($known, $reference['identifiers'] ?? []);
            $relations = array_merge($relations, $reference['relations'] ?? []);
            if (($reference['kind'] ?? '') === 'document') {
                $current = $this->reader->readCandidate(['document' => ['id' => $reference['document_id']], 'chunk_id' => $reference['chunk_id']], (string) $owner->project_key, $actor);
                if ($current !== null) {
                    $validDocumentHashes[] = hash('sha256', $this->masker->maskString($current['excerpt']));
                    $validDocumentHashes[] = hash('sha256', $current['excerpt']);
                }
            }
            $references[] = array_intersect_key($reference, array_flip(['kind', 'document_id', 'chunk_id', 'execution_id', 'identifiers', 'retrieved_at', 'status']));
        }
        $focus = $state['focus'];
        $focus['identifiers'] = array_values(array_intersect($focus['identifiers'] ?? [], $known));
        $subquestions = array_values(array_filter($state['subquestions'], fn ($sub) => array_diff($sub['identifiers'] ?? [], $known) === []));
        return $this->redactor->redact($this->masker->maskArray([
            'focus' => $focus, 'subquestions' => $subquestions, 'objective' => $state['objective'], 'language' => $state['language'],
            'dialogue' => $this->dialogue($owner, $beforeTurn),
            'known_identifiers' => array_values(array_unique($known)), 'verified_relations' => $relations,
            'references' => $references, 'communicated_fact_ids' => array_keys($state['communicated']),
            'already_communicated' => array_values(array_filter($state['communicated'], fn ($fact) =>
                (($fact['document_id'] ?? null) !== null && in_array($fact['evidence_hash'] ?? '', $validDocumentHashes, true))
                || (($fact['kind'] ?? '') === 'reported_text' && ($fact['source_hashes'] ?? []) !== []
                    && array_diff($fact['source_hashes'], $validDocumentHashes) === []))),
            'policy' => 'Memory is navigation, not evidence. Re-read documents under current permissions and refresh live tools before new factual answers. Never use prior assistant prose as evidence.',
        ]) ?? []);
    }

    public function citations(Model $owner, ?User $actor = null, ?RetrievalFilters $filters = null): array
    {
        $citations = [];
        foreach ($this->state($owner)['evidence'] as $reference) {
            if (($reference['kind'] ?? '') === 'document' && $this->authorized($owner, $reference, $actor, $filters)) {
                $citations[] = ['document_id' => $reference['document_id'], 'chunks' => [['chunk_id' => $reference['chunk_id']]]];
            }
        }
        return $citations;
    }

    /** Store found references independently of whether the generator chooses to cite them. */
    public function finish(Model $owner, Model $turn, Model $answer, array $evidence, array $claims = [], array $coverage = []): void
    {
        if (! $this->enabled()) {
            return;
        }
        $this->assertOwner($owner, $turn);
        $this->assertOwner($owner, $answer);
        $refs = $this->references($owner, $evidence);
        foreach ($refs as &$reference) {
            foreach ($claims as $claim) {
                if (($claim['document_id'] ?? null) !== null && ($reference['document_id'] ?? null) === $claim['document_id']
                    && ($reference['hash'] ?? '') === ($claim['evidence_hash'] ?? null)) {
                    $reference['identifiers'] = array_values(array_unique([...($reference['identifiers'] ?? []), ...($claim['entity_identifiers'] ?? [])]));
                    $reference['status'] = 'verified';
                }
            }
        }
        unset($reference);
        DB::transaction(function () use ($owner, $turn, $answer, $claims, $coverage, $refs): void {
            $locked = $owner->newQuery()->whereKey($owner->getKey())->lockForUpdate()->firstOrFail();
            $state = $this->state($locked);
            $turnKey = $turn->getTable().':'.$turn->getKey();
            if (isset($state['finished_turns'][$turnKey]) || data_get($answer->{$this->metadataColumn($answer)}, 'reasoning.turn') === $turnKey) {
                return;
            }
            // References are deduplicated by original identity/version, not generated answer text.
            $state['evidence'] = array_merge($state['evidence'], $refs);
            $text = (string) ($answer->content ?? data_get($answer->args_json, 'content', ''));
            foreach ($claims as $claim) {
                if (! is_string($claim['text'] ?? null) || ! str_contains($text, $claim['text'])) {
                    continue;
                }
                $id = $claim['fact_id'] ?? hash('sha256', ($claim['evidence_hash'] ?? '').'|'.mb_strtolower(trim(strip_tags($claim['text']))));
                $state['communicated'][$id] = ['message_id' => $answer->getKey(), 'turn' => $turn->getKey(),
                    'document_id' => $claim['document_id'] ?? null, 'execution_id' => $claim['tool_execution_id'] ?? null,
                    'text' => $claim['text'], 'evidence_hash' => $claim['evidence_hash'] ?? null,
                    'passage_hash' => isset($claim['quote']) ? hash('sha256', $claim['quote']) : null];
            }
            if ($claims === [] && $refs !== [] && trim($text) !== '' && $answer->agent_run_id === null) {
                // KB/widget prose has no per-claim verifier. Remember that it was SAID, not that
                // it is true; this is a repetition hint only, never a reusable evidence passage.
                $hashes = array_values(array_filter(array_column($refs, 'hash')));
                foreach (array_slice(preg_split('/\n\s*\n/u', trim($text)) ?: [], 0, 12) as $paragraph) {
                    if (mb_strlen($paragraph) > 1500) {
                        continue;
                    }
                    $id = hash('sha256', mb_strtolower(trim(strip_tags($paragraph))));
                    $state['communicated'][$id] = ['kind' => 'reported_text', 'verification' => 'not_claim_validated',
                        'message_id' => $answer->getKey(), 'turn' => $turn->getKey(), 'text' => $paragraph, 'source_hashes' => $hashes];
                }
            }
            if ($turn->getKey() === $state['focus_turn']) {
                foreach ($coverage as $sub) {
                    $status = $sub['status'];
                    unset($sub['status']);
                    $key = hash('sha256', json_encode($sub));
                    if (isset($state['subquestions'][$key])) {
                        $state['subquestions'][$key]['status'] = $status;
                    }
                    if ($status !== 'answered') {
                        $state['issues'][$key] = ['kind' => $status === 'conflicting' ? 'conflict' : 'missing_information', 'focus' => $sub, 'status' => 'open'];
                    } else {
                        unset($state['issues'][$key]);
                    }
                }
            }
            $state['finished_turns'][$turnKey] = $answer->getKey();
            $state['revision']++;
            $state = $this->bounded($state);
            $locked->forceFill(['reasoning_state' => $state])->save();
            $column = $this->metadataColumn($answer);
            $answer->forceFill([$column => array_merge((array) $answer->{$column}, ['reasoning' => [
                'turn' => $turnKey, 'revision' => $state['revision'], 'focus_after' => $state['focus'],
                'evidence_registered' => array_keys($refs), 'subquestions' => $coverage,
            ]])])->save();
        });
        $owner->refresh();
    }

    public function references(Model $owner, array $evidence): array
    {
        $references = [];
        foreach ($evidence['documents'] ?? [] as $document) {
            foreach ($document['evidence'] ?? $document['chunks'] ?? [] as $chunk) {
                $id = $document['document_id'] ?? null;
                $chunkId = $chunk['chunk_id'] ?? null;
                if (! is_numeric($id) || ! is_numeric($chunkId)) {
                    continue;
                }
                $hash = $chunk['evidence_hash'] ?? $chunk['chunk_hash'] ?? '';
                $references['doc:'.$id.':'.$chunkId] = ['kind' => 'document', 'document_id' => (int) $id,
                    'chunk_id' => (int) $chunkId, 'hash' => $hash, 'identifiers' => [], 'status' => 'found',
                    'retrieved_at' => now()->toIso8601String()];
            }
        }
        foreach ($evidence['api_tools'] ?? [] as $tool) {
            if (($tool['kind'] ?? '') === 'catalog' || ($tool['tool'] ?? '') === 'list_knowledge_documents') {
                continue; // Catalog row IDs are navigation, not business entities or live facts.
            }
            foreach (EvidenceProjector::records($tool) as $entry) {
                $record = $entry['record'];
                $ids = EvidenceProjector::identities($record);
                if ($ids === [] || ! is_int($tool['execution_id'] ?? null)) {
                    continue;
                }
                $relations = [];
                foreach ($record as $field => $value) {
                    if (preg_match('/(?:Id|Ids)$/', (string) $field)) {
                        foreach (is_array($value) ? $value : [$value] as $target) {
                            if (is_string($target) && mb_strlen($target) <= 120) {
                                $relations[] = ['from' => $ids[0], 'field' => $field, 'to' => $target, 'execution_id' => $tool['execution_id']];
                            }
                        }
                    }
                }
                $references['tool:'.$tool['tool'].':'.$ids[0]] = ['kind' => 'tool', 'execution_id' => $tool['execution_id'],
                    'tool' => $tool['tool'], 'record_path' => $entry['path'], 'hash' => $tool['evidence_hash'] ?? '',
                    'identifiers' => array_values(array_unique([...$ids, ...array_column($relations, 'to')])),
                    'relations' => $relations, 'status' => 'refresh_required', 'retrieved_at' => $tool['retrieved_at'] ?? now()->toIso8601String()];
            }
        }
        return $references;
    }

    /** Only an explicit recap may load old live snapshots; authorization is still checked NOW. */
    public function recapEvidence(AgentRun $run): array
    {
        if (! $this->enabled() || data_get($run->result_json, 'question_understanding.transition') !== 'recap') {
            return [];
        }
        $owner = $this->runOwner($run);
        if ($owner === null || $owner->project_key !== $run->project_key) {
            return [];
        }
        $ids = array_values(array_unique(array_merge(data_get($run->result_json, 'question_understanding.focus.identifiers', []),
            ...array_map(fn ($sub) => $sub['identifiers'] ?? [], data_get($run->result_json, 'question_understanding.subquestions', [])))));
        $snapshots = [];
        foreach ($this->state($owner)['evidence'] as $reference) {
            if (($reference['kind'] ?? '') !== 'tool' || ($ids !== [] && array_intersect($ids, $reference['identifiers'] ?? []) === [])
                || ! $this->authorized($owner, $reference, $run->user)) {
                continue;
            }
            $previous = \App\Models\AgentToolExecution::query()->find($reference['execution_id'])?->run;
            foreach (data_get($previous?->result_json, 'evidence.api_tools', []) as $tool) {
                if (($tool['execution_id'] ?? null) === $reference['execution_id']) {
                    $trial = [...$snapshots, $tool];
                    if (strlen(json_encode($trial)) <= 32000) {
                        $snapshots[$reference['execution_id']] = $tool;
                    }
                }
            }
        }
        return array_values($snapshots);
    }

    /** Repetition hints contain text only after the same original passage was reread. */
    public function communicatedForEvidence(Model $owner, array $evidence): array
    {
        $hashes = [];
        foreach ($evidence['documents'] ?? [] as $document) {
            foreach ($document['evidence'] ?? [] as $chunk) {
                $hashes[] = hash('sha256', (string) ($chunk['content'] ?? ''));
            }
        }
        foreach ($evidence['api_tools'] ?? [] as $tool) {
            foreach (EvidenceProjector::records($tool) as $entry) {
                $hashes[] = hash('sha256', json_encode($entry['record'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
        }
        return array_values(array_filter($this->state($owner)['communicated'], fn ($fact) => in_array($fact['passage_hash'] ?? '', $hashes, true)));
    }

    private function authorized(Model $owner, array $reference, ?User $actor, ?RetrievalFilters $filters = null): bool
    {
        if (($reference['tool'] ?? '') === 'list_knowledge_documents') {
            return false;
        }
        if (($reference['kind'] ?? '') === 'document') {
            return $this->reader->readCandidate(['document' => ['id' => $reference['document_id']], 'chunk_id' => $reference['chunk_id']], (string) $owner->project_key, $actor, $filters) !== null;
        }
        if (($reference['kind'] ?? '') !== 'tool') {
            return false;
        }
        $execution = \App\Models\AgentToolExecution::query()->whereKey($reference['execution_id'])->first();
        $run = $execution?->run;
        if (! $run instanceof AgentRun || (string) $run->tenant_id !== (string) $owner->tenant_id || $run->project_key !== $owner->project_key
            || ($owner instanceof Conversation ? $run->conversation_id != $owner->getKey() : $run->widget_session_id != $owner->getKey())) {
            return false;
        }
        $context = AgentExecutionContext::fromArray($run->only(['run_id', 'tenant_id', 'project_key', 'channel', 'actor_type', 'actor_id', 'locale', 'timezone']));
        return isset(app(AgentToolRegistry::class)->forContext($context, $actor)[$reference['tool']]);
    }

    /** Ordered dialogue acts guide interpretation; previous assistant facts are not reintroduced as evidence. */
    private function dialogue(Model $owner, ?int $beforeTurn): array
    {
        $query = $owner instanceof Conversation ? $owner->messages() : $owner->steps();
        $records = $query->when($beforeTurn !== null, fn ($q) => $q->where('id', '<', $beforeTurn))
            ->latest('id')->limit(12)->get()->reverse();
        $dialogue = [];
        foreach ($records as $record) {
            $metadata = (array) $record->{$this->metadataColumn($record)};
            $user = $owner instanceof Conversation ? $record->role === 'user' : $record->kind === 'user_message';
            if ($user) {
                $dialogue[] = ['role' => 'user', 'text' => mb_substr((string) ($record->content ?? data_get($record->args_json, 'content', '')), 0, 1500),
                    'interpreted_goal' => data_get($metadata, 'reasoning.understanding.intent'),
                    'action' => data_get($metadata, 'reasoning.understanding.action'),
                    'questions' => data_get($metadata, 'reasoning.understanding.subquestions', [])];
            } elseif (($owner instanceof Conversation && $record->role === 'assistant')
                || (! ($owner instanceof Conversation) && $record->kind === WidgetSessionStep::KIND_BOT_MESSAGE)) {
                $dialogue[] = ['role' => 'assistant', 'answer_status' => $metadata['completeness'] ?? null,
                    'answered_questions' => data_get($metadata, 'reasoning.subquestions', []),
                    // Offers are server-written dialogue acts, not business facts.
                    // This replaces parsing the previous answer for "would you like...".
                    'offered_actions' => array_values(array_filter((array) data_get($metadata, 'grounding.offered_actions', []),
                        fn ($action) => is_string($action) && \App\Services\Chat\QuestionAction::tryFrom($action) !== null)),
                    'clarification' => ($metadata['grounding']['status'] ?? '') === 'clarify'
                        ? mb_substr((string) ($record->content ?? data_get($record->args_json, 'content', '')), 0, 500) : null];
            }
        }
        return $dialogue;
    }

    private function bounded(array $state): array
    {
        $state = $this->redactor->redact($this->masker->maskArray($state) ?? []);
        $activeIds = $state['focus']['identifiers'] ?? [];
        // Stable sorting prunes old closed-topic references before active-topic references.
        uasort($state['evidence'], fn ($a, $b) => (int) (array_intersect($a['identifiers'] ?? [], $activeIds) !== [])
            <=> (int) (array_intersect($b['identifiers'] ?? [], $activeIds) !== []));
        $state['evidence'] = array_slice($state['evidence'], -(int) config('reasoning.max_evidence', 100), null, true);
        foreach (['entities', 'communicated', 'finished_turns', 'issues'] as $key) {
            $state[$key] = array_slice($state[$key], -100, null, true);
        }
        $state['subquestions'] = array_slice($state['subquestions'], -20, null, true);
        while (strlen(json_encode($state)) > (int) config('reasoning.max_state_bytes', 65536)) {
            foreach (['evidence', 'communicated', 'finished_turns', 'entities', 'subquestions', 'issues'] as $key) {
                if ($state[$key] !== []) {
                    array_shift($state[$key]);
                    continue 2;
                }
            }
            break;
        }
        return $state;
    }

    private function assertOwner(Model $owner, Model $record): void
    {
        if (! ($owner instanceof Conversation || $owner instanceof WidgetSession)
            || (string) $owner->tenant_id !== (string) app(\App\Support\TenantContext::class)->current()
            || ($owner instanceof Conversation ? $record->conversation_id != $owner->getKey() : $record->widget_session_id != $owner->getKey())) {
            throw new \DomainException('reasoning_owner_mismatch');
        }
    }

    private function metadataColumn(Model $record): string
    {
        return $record instanceof \App\Models\Message ? 'metadata' : 'args_json';
    }
}

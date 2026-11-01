<?php

declare(strict_types=1);

namespace App\Services\Chat\Reasoning;

use App\Agent\AgentAnswer;
use App\Agent\AgentExecutionContext;
use App\Agent\Tools\AgentToolRegistry;
use App\Models\AgentRun;
use App\Models\AgentToolExecution;
use App\Models\Conversation;
use App\Models\SessionMemoryFact;
use App\Models\SessionMemoryObservation;
use App\Models\User;
use App\Models\WidgetSession;
use App\Services\Chat\QuestionAction;
use App\Services\Chat\QuestionUnderstanding;
use App\Services\Kb\Investigation\KbSourceReader;
use App\Services\Kb\Retrieval\RetrievalFilters;
use App\Services\Widget\WidgetPiiMasker;
use App\Support\TenantContext;
use App\Support\SensitivePayloadRedactor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** A fact retains its original evidence and acquisition clock across every reuse. */
final readonly class SessionFactMemory
{
    public function __construct(private KbSourceReader $reader, private WidgetPiiMasker $masker, private SensitivePayloadRedactor $redactor) {}

    public function enabled(): bool
    {
        return config('reasoning.enabled', true) && config('reasoning.session_memory.enabled', false);
    }

    public static function decisionSchema(): array
    {
        return ['type' => 'array', 'maxItems' => 10, 'items' => ['type' => 'object', 'additionalProperties' => false,
            'required' => ['task_index', 'fact_ids', 'refresh_requested', 'complete', 'collection', 'scope_matches', 'missing_question'],
            'properties' => [
                'task_index' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 9],
                'fact_ids' => ['type' => 'array', 'maxItems' => 100, 'items' => ['type' => 'string']],
                'refresh_requested' => ['type' => 'boolean'], 'complete' => ['type' => 'boolean'],
                'collection' => ['type' => 'boolean'], 'scope_matches' => ['type' => 'boolean'],
                'missing_question' => ['type' => 'string', 'maxLength' => 500],
            ]]];
    }

    /** Only source-authorized facts reach interpretation; expired facts are explicitly historical. */
    public function projection(Model $owner, ?User $actor = null, ?RetrievalFilters $filters = null, array $allowedTools = [], ?int $beforeTurn = null, array $identifiers = [], ?string $question = null): array
    {
        if (! $this->enabled()) return [];
        $facts = [];
        $bytes = 0;
        $seen = [];
        $query = $this->observations($owner, $actor)->when($beforeTurn !== null, fn ($q) => $q->where('turn_id', '<', $beforeTurn));
        if ($identifiers === [] && $question !== null) {
            // Exact identifier membership is a local lookup, not interpretation of user intent.
            foreach (SessionMemoryFact::query()->whereHas('observation', fn ($q) => $q->whereIn('id', (clone $query)->select('id')))
                ->select(['id', 'subject_identifiers'])->orderBy('id')->lazy(100) as $fact) {
                foreach ($fact->subject_identifiers as $id) if (EvidenceProjector::contains($question, $id)) $identifiers[] = $id;
            }
            $identifiers = array_slice(array_unique($identifiers), 0, 12);
        }
        if ($identifiers !== []) $query->whereHas('facts', function ($q) use ($identifiers): void {
            $q->where(function ($q) use ($identifiers): void {
                foreach ($identifiers as $id) $q->orWhereJsonContains('subject_identifiers', $id);
            });
        });
        foreach ($query
            ->with('facts')->orderByDesc('acquired_at')->orderByDesc('id')->lazy(50) as $observation) {
            if (! $this->authorized($owner, $observation, $actor, $filters, $allowedTools)) continue;
            foreach ($observation->facts as $fact) {
                $entry = $this->factView($fact, $observation);
                $identity = $entry['namespace'].'|'.json_encode($entry['subject_identifiers']).'|'.$entry['field'];
                if ($entry['field'] !== 'statement' && isset($seen[$identity])) continue;
                $seen[$identity] = true;
                $size = strlen(json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                if (count($facts) >= (int) config('reasoning.session_memory.max_prompt_facts', 100)) return $facts;
                if ($bytes + $size > (int) config('reasoning.session_memory.max_prompt_bytes', 32768)) continue;
                $facts[] = $entry;
                $bytes += $size;
            }
        }
        return $facts;
    }

    /** Model coverage is a proposal; freshness, identity, fields and conflicts are host gates. */
    public function resolve(Model $owner, QuestionUnderstanding $understanding, ?User $actor = null, ?RetrievalFilters $filters = null, array $allowedTools = [], ?int $beforeTurn = null): QuestionUnderstanding
    {
        if (! $this->enabled() || ! $understanding->available || $understanding->needsClarification
            || $understanding->isAssistantConversation() || $understanding->asksForProvenance() || $understanding->asksToReadSource()) return $understanding;
        $available = array_column($this->projection($owner, $actor, $filters, $allowedTools, $beforeTurn, $understanding->targetIdentifiers()), null, 'fact_id');
        $subs = $understanding->memoryResolution['original_subquestions'] ?? $understanding->subquestions;
        $originalSubs = $subs;
        $tasks = $selected = [];
        foreach ($subs as $index => &$sub) {
            $proposals = array_values(array_filter($understanding->memoryDecisions, fn ($d) => is_array($d) && ($d['task_index'] ?? null) === $index));
            $proposal = count($proposals) === 1 ? $proposals[0] : [];
            $ids = is_array($proposal['fact_ids'] ?? null) ? $proposal['fact_ids'] : [];
            $refresh = ($proposal !== [] && ($proposal['refresh_requested'] ?? true) !== false) || $understanding->effectiveAction() === QuestionAction::Recheck;
            $historical = $understanding->effectiveAction() === QuestionAction::Recap;
            $accepted = $excluded = [];
            foreach ($ids as $id) {
                $fact = is_string($id) ? ($available[$id] ?? null) : null;
                $reason = $fact === null ? 'unavailable' : ((! $historical && $fact['status'] === 'expired') ? 'expired' : null);
                if ($reason === null && (($sub['identifiers'] ?? []) === [] || array_intersect($sub['identifiers'], $fact['subject_identifiers']) === [])) $reason = 'entity_mismatch';
                if ($refresh) $reason = 'refresh_requested';
                if ($reason !== null) $excluded[] = ['fact_id' => $id, 'reason' => $reason];
                else $accepted[$id] = $fact;
            }
            // Never resolve an equal code in two source namespaces implicitly.
            $related = array_filter($available, fn ($fact) => ($historical || $fact['status'] === 'valid')
                && array_intersect($sub['identifiers'] ?? [], $fact['subject_identifiers']) !== []
                && in_array($fact['field'], array_column($accepted, 'field'), true));
            $namespaces = array_unique(array_filter(array_column($related, 'namespace'), fn ($namespace) => str_starts_with($namespace, 'tool:')));
            $conflict = count($namespaces) > 1 || $this->conflicting($accepted);
            if ($conflict) {
                $excluded = [...$excluded, ...array_map(fn ($id) => ['fact_id' => $id, 'reason' => 'conflicting_sources'], array_keys($accepted))];
                $accepted = [];
            }
            $fields = $sub['fields'] ?? [];
            $present = array_column($accepted, 'field');
            $specific = $fields !== [] && ! in_array('*', $fields, true);
            $complete = ($proposal['complete'] ?? false) === true && $accepted !== [] && $excluded === [] && ! $refresh;
            if ($specific && array_diff($fields, $present) !== [] && ! in_array('statement', $present, true)) $complete = false;
            if (($proposal['collection'] ?? true) !== false
                && (! in_array('$collection', $present, true) || ($proposal['scope_matches'] ?? false) !== true)) $complete = false;
            if ($complete) {
                $sub['requires_research'] = false;
                $sub['context_mode'] = 'conversation';
                $sub['kb_queries'] = [];
            } elseif ($understanding->needsResearch() || $ids !== [] || $refresh) {
                $sub['requires_research'] = true;
                if (($sub['kb_queries'] ?? []) === []) $sub['kb_queries'] = [$sub['question']];
                $missing = $proposal['missing_question'] ?? '';
                if ($accepted !== [] && is_string($missing) && trim($missing) !== '' && mb_strlen($missing) <= 500
                    && ! preg_match('~https?://|\bcurl\b~iu', $missing)) $sub['kb_queries'] = [trim($missing)];
            }
            $tasks[] = ['task_index' => $index, 'question' => $sub['question'] ?? '',
                'decision' => $complete ? 'reuse' : ($accepted !== [] ? 'partial' : ($refresh ? 'refresh' : 'research')),
                'fact_ids' => array_keys($accepted), 'excluded' => $excluded, 'historical' => $historical,
                'missing_question' => $complete ? null : ($proposal['missing_question'] ?? null)];
            $selected = [...$selected, ...array_keys($accepted)];
        }
        unset($sub);
        return $understanding->withMemoryRouting($subs, ['tasks' => $tasks, 'fact_ids' => array_values(array_unique($selected)),
            'original_subquestions' => $originalSubs,
            'complete' => $tasks !== [] && count(array_filter($tasks, fn ($t) => $t['decision'] === 'reuse')) === count($tasks)]);
    }

    /** Recheck selected observations at use time, including retries and source selection changes. */
    public function evidence(Model $owner, QuestionUnderstanding $understanding, ?User $actor = null, ?RetrievalFilters $filters = null, array $allowedTools = []): array
    {
        $safe = ['documents' => [], 'api_tools' => []];
        if (! $this->enabled()) return $safe;
        $ids = $understanding->memoryResolution['fact_ids'] ?? [];
        $historical = $understanding->effectiveAction() === QuestionAction::Recap;
        foreach ($this->observations($owner, $actor)->whereHas('facts', fn ($q) => $q->whereIn('id', $ids))->get() as $observation) {
            if ((! $historical && $observation->expires_at->lessThanOrEqualTo(now()))
                || ! $this->authorized($owner, $observation, $actor, $filters, $allowedTools)) continue;
            foreach (['documents', 'api_tools'] as $kind) {
                foreach ($observation->evidence_json[$kind] ?? [] as $source) {
                    $safe[$kind][] = [...$source, 'session_memory_observation_id' => $observation->id,
                        'historical_context' => $historical, 'acquired_at' => $observation->acquired_at->toIso8601String(),
                        'expires_at' => $observation->expires_at->toIso8601String()];
                }
            }
        }
        return $safe;
    }

    /** Called by the existing evidence access boundary, never by a remote payload. */
    public function allowsTool(AgentRun $run, array $tool): bool
    {
        $observation = $this->selectedObservation($run, $tool);
        if ($observation === null) return false;
        $original = $observation->evidence_json['api_tools'][0] ?? [];
        return ($original['execution_id'] ?? null) === ($tool['execution_id'] ?? null)
            && ($original['evidence_hash'] ?? null) === ($tool['evidence_hash'] ?? null)
            && ($original['result'] ?? null) === ($tool['result'] ?? null);
    }

    public function allowsDocument(AgentRun $run, array $document): bool
    {
        $observation = $this->selectedObservation($run, $document);
        $original = $observation?->evidence_json['documents'][0] ?? [];
        if (($original['document_id'] ?? null) !== ($document['document_id'] ?? null)) return false;
        foreach ($document['evidence'] ?? [] as $chunk) {
            if (! array_filter($original['evidence'] ?? [], fn ($old) => ($old['chunk_id'] ?? null) === ($chunk['chunk_id'] ?? null)
                && ($old['evidence_hash'] ?? null) === ($chunk['evidence_hash'] ?? null) && ($old['content'] ?? null) === ($chunk['content'] ?? null))) return false;
        }
        return $observation !== null;
    }

    private function selectedObservation(AgentRun $run, array $source): ?SessionMemoryObservation
    {
        if (! $this->enabled() || ! isset($source['session_memory_observation_id'])) return null;
        $owner = app(ConversationReasoning::class)->runOwner($run);
        if ($owner === null) return null;
        $understanding = QuestionUnderstanding::fromArray((array) data_get($run->result_json, 'question_understanding', []));
        $observation = $this->observations($owner, $run->user)
            ->whereHas('facts', fn ($q) => $q->whereIn('id', $understanding->memoryResolution['fact_ids'] ?? []))
            ->whereKey($source['session_memory_observation_id'] ?? 0)->first();
        return $observation === null || ($understanding->effectiveAction() !== QuestionAction::Recap && $observation->expires_at->lessThanOrEqualTo(now())) ? null : $observation;
    }

    /** Only exact structured fields or already verified claims are admitted here. No LLM calls. */
    public function consolidate(Model $owner, Model $turn, array $evidence, array $claims, ?User $actor = null): void
    {
        if (! $this->enabled()) return;
        $this->observations($owner, $actor); // Assert scope even for an empty update.
        if ((string) $turn->tenant_id !== (string) $owner->tenant_id
            || ($owner instanceof Conversation ? $turn->conversation_id != $owner->id : $turn->widget_session_id != $owner->id)) {
            throw new \DomainException('session_memory_turn_mismatch');
        }
        $metadata = $turn instanceof \App\Models\Message ? $turn->metadata : $turn->args_json;
        $understanding = QuestionUnderstanding::fromArray((array) data_get($metadata, 'reasoning.understanding', []));
        foreach ($evidence['api_tools'] ?? [] as $tool) {
            // A memory import must never create a new acquisition or extend its TTL.
            if (isset($tool['session_memory_observation_id']) || ($tool['historical_context'] ?? false)) continue;
            if (($tool['kind'] ?? '') === 'catalog' || ($tool['tool'] ?? '') === 'list_knowledge_documents') continue;
            $observation = $this->newObservation($owner, $turn, ['api_tools' => [$tool]],
                'tool:'.($tool['executor_reference'] ?? '').':'.($tool['tool'] ?? ''), $tool['retrieved_at'] ?? null);
            if ($observation === null || ! $this->authorized($owner, $observation, $actor, null, [$tool['tool'] ?? ''])) continue;
            foreach (EvidenceProjector::records($tool) as $entry) {
                $identifiers = EvidenceProjector::identities($entry['record'], $tool['presentation']['identity_fields'] ?? []);
                if ($identifiers === [] || ($understanding->targetIdentifiers() !== [] && array_intersect($identifiers, $understanding->targetIdentifiers()) === [])) continue;
                if (data_get($tool, 'result.meta.ambiguous') === true) continue;
                foreach (\Illuminate\Support\Arr::dot($entry['record']) as $field => $value) {
                    if ((! is_scalar($value) && $value !== null) || strlen(json_encode($value)) > 4000 || strlen((string) $field) > 200) continue;
                    $this->storeFact($observation, $identifiers, (string) $field, $value,
                        ['method' => 'exact_record_field', 'record_path' => $entry['path'], 'field_path' => (string) $field,
                            'evidence_hash' => $tool['evidence_hash']]);
                    foreach ($tool['presentation']['field_aliases'] ?? [] as $alias => $sourceField) {
                        if ($sourceField === $field && $alias !== $field) $this->storeFact($observation, $identifiers, $alias, $value,
                            ['method' => 'exact_record_field', 'record_path' => $entry['path'], 'field_path' => (string) $field, 'evidence_hash' => $tool['evidence_hash']]);
                    }
                }
            }
            // Collection reuse requires explicit source attestation of completeness.
            if (data_get($tool, 'result.meta.complete') === true && data_get($tool, 'result.meta.has_more') === false
                && data_get($tool, 'result.meta.ambiguous') !== true && $understanding->targetIdentifiers() !== []) {
                $this->storeFact($observation, $understanding->targetIdentifiers(), '$collection', array_column(EvidenceProjector::records($tool), 'record'),
                    ['method' => 'complete_collection', 'arguments' => $tool['arguments'] ?? [], 'evidence_hash' => $tool['evidence_hash']]);
            }
        }
        foreach ($claims as $claim) {
            if (! is_array($claim) || ! is_string($claim['text'] ?? null) || trim($claim['text']) === '') continue;
            // A verified claim still has to bind to the original source. Prose is never its own proof.
            $bound = app(EvidenceProjector::class)->bind($evidence, $claim, [], (string) $owner->tenant_id, (string) $owner->project_key);
            if (($bound['reason'] ?? null) !== null) continue;
            $source = $this->claimSource($evidence, $claim);
            if ($source === null || isset($source['source']['session_memory_observation_id']) || ($source['source']['historical_context'] ?? false)) continue;
            $observation = $this->newObservation($owner, $turn, [$source['kind'] => [$source['source']]], $source['namespace'], $source['acquired_at']);
            if ($observation === null || ! $this->authorized($owner, $observation, $actor, null,
                isset($source['source']['tool']) ? [$source['source']['tool']] : [])) continue;
            $identifiers = array_values(array_unique([...($claim['entity_identifiers'] ?? []), ...array_filter($understanding->targetIdentifiers(),
                fn ($id) => EvidenceProjector::contains($bound['quote'], $id))]));
            if ($identifiers === []) continue;
            $this->storeFact($observation, $identifiers, 'statement', $claim['text'],
                ['method' => 'verified_claim', 'evidence_hash' => $claim['evidence_hash'], 'verification' => $claim['validation'] ?? 'grounding'], $claim);
        }
    }

    /** Exact rendering reuses the verification, not a previous assistant message. */
    public function answer(Model $owner, QuestionUnderstanding $understanding, ?User $actor = null, ?RetrievalFilters $filters = null, array $allowedTools = []): ?AgentAnswer
    {
        if (! ($understanding->memoryResolution['complete'] ?? false) || $understanding->requiresComposition) return null;
        $available = array_column($this->projection($owner, $actor, $filters, $allowedTools, identifiers: $understanding->targetIdentifiers()), null, 'fact_id');
        $ids = $understanding->memoryResolution['fact_ids'] ?? [];
        if ($ids === [] || array_diff($ids, array_keys($available)) !== []) return null;
        $historical = $understanding->effectiveAction() === QuestionAction::Recap;
        $claims = $lines = $dates = [];
        foreach ($ids as $id) {
            $fact = $available[$id];
            if (! $historical && $fact['status'] !== 'valid') return null;
            $value = is_string($fact['value']) ? $fact['value'] : json_encode($fact['value'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $text = $fact['field'] === 'statement' ? $value : implode(', ', $fact['subject_identifiers']).' — '.$fact['field'].': '.$value;
            $lines[] = $text;
            $dates[] = $fact['acquired_at'];
            $claims[] = ['text' => $text, 'session_fact_id' => $id, 'verification_ref' => $fact['verification_ref']];
        }
        $sources = $this->evidence($owner, $understanding, $actor, $filters, $allowedTools);
        if ($sources['documents'] === [] && $sources['api_tools'] === []) return null;
        $italian = str_starts_with($understanding->language ?? 'en', 'it');
        $text = implode("\n\n", $lines)."\n\n".($italian ? 'Dati consultati: ' : 'Sources consulted: ').implode(', ', array_unique($dates)).'.';
        $citations = array_map(fn ($document) => [
            ...array_diff_key($document, array_flip(['evidence', 'session_memory_observation_id', 'historical_context', 'acquired_at', 'expires_at'])),
            'source_type' => $document['source_type'] ?? null, 'origin' => $document['origin'] ?? 'primary',
            'chunks' => array_map(fn ($chunk) => ['chunk_id' => $chunk['chunk_id'], 'snippet' => $chunk['content'],
                'evidence_hash' => $chunk['evidence_hash'], 'heading' => $chunk['heading'] ?? null], $document['evidence']),
        ], $sources['documents']);
        return new AgentAnswer($text, $understanding->language ?? 'en', 'complete', $citations,
            array_map(fn ($t) => array_intersect_key($t, array_flip(['execution_id', 'tool', 'display_name', 'kind', 'retrieved_at', 'evidence_hash'])), $sources['api_tools']),
            grounding: ['status' => 'grounded', 'model' => 'verified_session_memory', 'claims' => [], 'reused_claims' => $claims,
                'session_memory' => $understanding->memoryResolution, 'semantic_validation' => [],
                'subquestions' => array_map(fn ($sub) => [...$sub, 'status' => 'answered'], $understanding->subquestions)]);
    }

    public function snapshot(Model $owner, ?User $actor = null): array
    {
        if (! $this->enabled()) return ['enabled' => false];
        return ['schema_version' => 2, 'ttl_seconds' => (int) config('reasoning.session_memory.ttl_seconds', 300),
            'observations' => $this->observations($owner, $actor)->with('facts')->orderBy('id')->get()->map(fn ($observation) => [
                'id' => $observation->id, 'namespace' => $observation->namespace, 'origin_turn_id' => $observation->turn_id,
                'acquired_at' => $observation->acquired_at->toIso8601String(), 'expires_at' => $observation->expires_at->toIso8601String(),
                'provenance' => [
                    'documents' => array_map(fn ($document) => ['document_id' => $document['document_id'], 'title' => $document['title'] ?? null,
                        'chunks' => array_map(fn ($chunk) => array_intersect_key($chunk, array_flip(['chunk_id', 'evidence_hash'])), $document['evidence'] ?? [])], $observation->evidence_json['documents'] ?? []),
                    'api_tools' => array_map(fn ($tool) => array_intersect_key($tool, array_flip(['tool', 'execution_id', 'executor_reference', 'evidence_hash', 'arguments'])), $observation->evidence_json['api_tools'] ?? []),
                ],
                'facts' => $observation->facts->map(fn ($fact) => $this->factView($fact, $observation))->all(),
            ])->all()];
    }

    private function observations(Model $owner, ?User $actor): Builder
    {
        if (! ($owner instanceof Conversation || $owner instanceof WidgetSession) || ! $owner->exists
            || (string) $owner->tenant_id !== (string) app(TenantContext::class)->current()
            || ($owner instanceof Conversation && $actor !== null && $owner->user_id != $actor->id)) throw new \DomainException('session_memory_scope_mismatch');
        return SessionMemoryObservation::query()->forTenant((string) $owner->tenant_id)->where('owner_key', $owner->getTable().':'.$owner->getKey())
            ->where('project_key', $owner->project_key)
            ->where($owner instanceof Conversation ? 'conversation_id' : 'widget_session_id', $owner->getKey());
    }

    private function authorized(Model $owner, SessionMemoryObservation $observation, ?User $actor, ?RetrievalFilters $filters, array $allowedTools): bool
    {
        foreach ($observation->evidence_json['documents'] ?? [] as $document) {
            foreach ($document['evidence'] ?? [] as $chunk) {
                $current = $this->reader->readCandidate(['document' => ['id' => $document['document_id']], 'chunk_id' => $chunk['chunk_id']], $owner->project_key, $actor, $filters);
                if ($current === null || ! is_string($chunk['content'] ?? null) || $chunk['content'] === ''
                    || $this->masker->maskString($current['excerpt']) !== $chunk['content']) return false;
            }
        }
        foreach ($observation->evidence_json['api_tools'] ?? [] as $tool) {
            if (! in_array($tool['tool'] ?? '', $allowedTools, true)) return false;
            $execution = AgentToolExecution::query()->find($tool['execution_id'] ?? 0);
            $run = $execution?->run;
            if ($run === null || $execution->status !== 'completed' || $execution->tool_name !== ($tool['tool'] ?? null)
                || (string) $run->tenant_id !== (string) $owner->tenant_id || $run->project_key !== $owner->project_key
                || ($owner instanceof Conversation ? $run->conversation_id != $owner->id || $run->user_id != $owner->user_id : $run->widget_session_id != $owner->id)) return false;
            $context = AgentExecutionContext::fromArray($run->toArray());
            $definition = app(AgentToolRegistry::class)->forContext($context, $actor)[$tool['tool']] ?? null;
            if ($definition === null || $definition->executorReference !== ($tool['executor_reference'] ?? null)
                || ! hash_equals((string) ($tool['evidence_hash'] ?? ''), hash('sha256', json_encode($tool['result'] ?? [], JSON_UNESCAPED_UNICODE)))) return false;
        }
        return ($observation->evidence_json['documents'] ?? []) !== [] || ($observation->evidence_json['api_tools'] ?? []) !== [];
    }

    private function newObservation(Model $owner, Model $turn, array $evidence, string $namespace, mixed $acquiredAt): ?SessionMemoryObservation
    {
        if (! is_string($acquiredAt) || $acquiredAt === '') return null;
        try { $acquired = CarbonImmutable::parse($acquiredAt); } catch (\Throwable) { return null; }
        if ($acquired->isFuture()) return null;
        $evidence = $this->masker->maskArray($evidence) ?? [];
        // Do not duplicate credentials into an archive, or silently change a verified source hash.
        if ($this->redactor->redact($evidence) !== $evidence) return null;
        return SessionMemoryObservation::query()->firstOrCreate([
            'tenant_id' => (string) $owner->tenant_id, 'owner_key' => $owner->getTable().':'.$owner->getKey(),
            'source_key' => hash('sha256', json_encode([$namespace, $evidence, $acquired->toIso8601String()], JSON_UNESCAPED_UNICODE)),
        ], ['project_key' => $owner->project_key, 'conversation_id' => $owner instanceof Conversation ? $owner->id : null,
            'widget_session_id' => $owner instanceof WidgetSession ? $owner->id : null, 'turn_id' => $turn->getKey(),
            'namespace' => $namespace, 'evidence_json' => $evidence, 'acquired_at' => $acquired,
            'expires_at' => $acquired->addSeconds((int) config('reasoning.session_memory.ttl_seconds', 300))]);
    }

    private function storeFact(SessionMemoryObservation $observation, array $identifiers, string $field, mixed $value, array $verification, ?array $claim = null): void
    {
        sort($identifiers);
        $id = hash('sha256', json_encode([$observation->id, $identifiers, $field, $value], JSON_UNESCAPED_UNICODE));
        SessionMemoryFact::query()->firstOrCreate(['id' => $id], ['observation_id' => $observation->id,
            'subject_identifiers' => $identifiers, 'field' => $field, 'value_json' => $value,
            'verification_json' => $verification, 'claim_json' => $claim]);
    }

    private function factView(SessionMemoryFact $fact, SessionMemoryObservation $observation): array
    {
        return ['fact_id' => $fact->id, 'subject_identifiers' => $fact->subject_identifiers, 'field' => $fact->field, 'value' => $fact->value_json,
            'namespace' => $observation->namespace, 'source_ref' => 'observation:'.$observation->id,
            'verification_ref' => $fact->verification_json, 'acquired_at' => $observation->acquired_at->toIso8601String(),
            'expires_at' => $observation->expires_at->toIso8601String(), 'status' => $observation->expires_at->greaterThan(now()) ? 'valid' : 'expired'];
    }

    private function conflicting(array $facts): bool
    {
        $values = [];
        foreach ($facts as $fact) {
            if ($fact['field'] === 'statement') continue;
            $key = $fact['namespace'].'|'.json_encode($fact['subject_identifiers']).'|'.$fact['field'];
            $value = json_encode($fact['value']);
            if (isset($values[$key]) && $values[$key] !== $value) return true;
            $values[$key] = $value;
        }
        return false;
    }

    private function claimSource(array $evidence, array $claim): ?array
    {
        foreach ($evidence['documents'] ?? [] as $document) {
            if (($document['document_id'] ?? null) !== ($claim['document_id'] ?? null)) continue;
            foreach ($document['evidence'] ?? [] as $chunk) {
                if (($chunk['evidence_hash'] ?? null) === ($claim['evidence_hash'] ?? null)) return ['kind' => 'documents',
                    'source' => [...$document, 'evidence' => [$chunk]], 'namespace' => 'document:'.$document['document_id'],
                    'acquired_at' => $chunk['retrieved_at'] ?? $document['retrieved_at'] ?? null];
            }
        }
        foreach ($evidence['api_tools'] ?? [] as $tool) {
            if (($tool['execution_id'] ?? null) === ($claim['tool_execution_id'] ?? null) && ($tool['evidence_hash'] ?? null) === ($claim['evidence_hash'] ?? null)) return ['kind' => 'api_tools',
                'source' => $tool, 'namespace' => 'tool:'.($tool['executor_reference'] ?? '').':'.$tool['tool'], 'acquired_at' => $tool['retrieved_at'] ?? null];
        }
        return null;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Agent\AgentAnswer;
use App\Agent\AgentExecutionContext;
use App\Agent\AgentRetrievalFiltersFactory;
use App\Agent\Tools\AgentLiveSourceSelection;
use App\Agent\Tools\AgentToolRegistry;
use App\Models\AgentRun;
use App\Models\AgentToolExecution;
use App\Models\Conversation;
use App\Models\User;
use App\Models\WidgetSession;
use App\Models\WidgetSessionStep;
use App\Services\Chat\Reasoning\ConversationReasoning;
use App\Services\Chat\Reasoning\EvidenceProjector;
use App\Services\Kb\Investigation\KbSourceReader;
use App\Services\Kb\Retrieval\RetrievalFilters;
use App\Services\Widget\WidgetPiiMasker;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;

/** Explains recorded attribution, never answers business questions from old prose. */
final readonly class AnswerProvenance
{
    public function __construct(private KbSourceReader $reader, private WidgetPiiMasker $masker) {}

    public function forRun(AgentRun $run, string $locale): AgentAnswer
    {
        $memory = app(ConversationReasoning::class);
        $context = AgentExecutionContext::fromArray($run->only(['run_id', 'tenant_id', 'project_key', 'channel', 'actor_type', 'actor_id', 'locale', 'timezone']));
        $context->assertMatches($run);
        $owner = $memory->runOwner($run);
        $turn = $memory->runTurn($run);
        if ($owner === null || $turn === null || $owner->project_key !== $run->project_key) {
            throw new \DomainException('provenance_owner_mismatch');
        }

        return $this->explain($owner, $turn, $run->user, $locale,
            app(AgentRetrievalFiltersFactory::class)->forRun($run, $context),
            data_get($run->input_json, 'live_sources'));
    }

    public function explain(Model $owner, Model $turn, ?User $actor, string $locale, ?RetrievalFilters $filters = null, ?array $liveSources = null): AgentAnswer
    {
        $chat = $owner instanceof Conversation;
        if (! ($chat || $owner instanceof WidgetSession)
            || (string) $owner->tenant_id !== (string) app(TenantContext::class)->current()
            || ($chat ? $turn->conversation_id != $owner->getKey() || $actor?->id != $owner->user_id
                : $turn->widget_session_id != $owner->getKey())) {
            throw new \DomainException('provenance_owner_mismatch');
        }
        $column = $chat ? 'metadata' : 'args_json';
        $understanding = QuestionUnderstanding::fromArray(data_get($turn->{$column}, 'reasoning.understanding', ['intent' => '']));
        if (! $understanding->asksForProvenance()) {
            throw new \DomainException('provenance_intent_required');
        }
        // Never skip an uncited answer to silently attribute some older reply instead.
        // The upper bound also prevents a concurrent/future answer entering this turn.
        $previous = ($chat ? $owner->messages()->where('role', 'assistant')
            : $owner->steps()->where('kind', WidgetSessionStep::KIND_BOT_MESSAGE))
            ->where('id', '<', $turn->getKey())->latest('id')->first();
        $metadata = (array) ($previous?->{$column} ?? []);
        $ids = $understanding->targetIdentifiers();
        $sources = $citations = $toolSources = [];
        $unavailable = 0;
        $recorded = 0;
        $authorizedTools = null;

        // Only the final answer's citations are candidates. The reasoning memory and
        // retrieval envelope can contain unused/rejected sources and are NOT a source list.
        foreach (array_slice((array) ($metadata['citations'] ?? []), 0, 100) as $citation) {
            if (! is_array($citation) || ! in_array($citation['origin'] ?? 'primary', ['primary', 'related'], true)) {
                continue;
            }
            $recorded++;
            $source = null;
            foreach ((array) ($citation['chunks'] ?? []) as $chunk) {
                if (! is_array($chunk)) {
                    continue;
                }
                $source = $this->reader->readCandidate(['document' => ['id' => $citation['document_id'] ?? 0],
                    'chunk_id' => $chunk['chunk_id'] ?? 0], $owner->project_key, $actor, $filters);
                if ($source !== null) {
                    break;
                }
            }
            if ($source === null) {
                $unavailable++;
                continue;
            }
            $claims = array_values(array_filter((array) data_get($metadata, 'grounding.claims', []),
                fn ($claim) => is_array($claim) && ($claim['document_id'] ?? null) == $source['document_id']));
            if (! $this->matches($ids, $claims, (array) ($citation['claims'] ?? []))) {
                continue;
            }
            $key = 'document:'.$source['document_id'];
            $sources[$key] = ['kind' => in_array($source['source_type'], ['imap', 'email'], true) ? 'email' : 'document',
                'document_id' => $source['document_id'], 'title' => $this->label($source['title']),
                // Explain identity, not old passages; an authorized document may have changed.
                'status' => 'authorized_reference', 'usage' => $claims !== [] || ($citation['claims'] ?? []) !== [] ? 'claim_linked' : 'cited'];
            $citations[$key] = ['document_id' => $source['document_id'], 'title' => $this->masker->maskString($source['title']),
                // The UI opens sources by document ID. Do not echo raw URLs, whose
                // query/user-info may contain credentials or signed access tokens.
                'source_path' => null, 'source_type' => $source['source_type'],
                'project_key' => $owner->project_key, 'origin' => 'primary', 'chunks_used' => 0,
                // Keep the anchor identity for repeated attribution, but do not copy old text.
                'chunks' => [['chunk_id' => $chunk['chunk_id']]], 'headings' => []];
        }

        // A recorded tool call alone is not proof it contributed to the answer.
        // Use tool_sources selected by the synthesizer, then check its durable execution,
        // original envelope hash and CURRENT connector authorization without invoking it.
        foreach (array_slice((array) ($metadata['tool_sources'] ?? []), 0, 100) as $toolSource) {
            if (! is_array($toolSource)) {
                continue;
            }
            $recorded++;
            $execution = AgentToolExecution::query()->whereKey($toolSource['execution_id'] ?? 0)
                ->whereHas('run', fn ($query) => $query->forTenant($owner->tenant_id)->where('project_key', $owner->project_key)
                    ->where($chat ? 'conversation_id' : 'widget_session_id', $owner->getKey()))->first();
            $run = $execution?->run;
            if ($execution === null || $run === null || $execution->status !== 'completed'
                || ($chat ? $run->user_id != $actor?->id : $run->widget_identity_id != $owner->widget_identity_id)
                || $execution->tool_name !== ($toolSource['tool'] ?? null)) {
                $unavailable++;
                continue;
            }
            $context = AgentExecutionContext::fromArray($run->only(['run_id', 'tenant_id', 'project_key', 'channel', 'actor_type', 'actor_id', 'locale', 'timezone']));
            $authorizedTools ??= app(AgentLiveSourceSelection::class)->apply(app(AgentToolRegistry::class)->forContext($context, $actor), $liveSources);
            $definition = $authorizedTools[$execution->tool_name] ?? null;
            $original = collect(data_get($run->result_json, 'evidence.api_tools', []))
                ->first(fn ($tool) => is_array($tool) && ($tool['execution_id'] ?? null) === $execution->id && ($tool['tool'] ?? null) === $execution->tool_name);
            if ($definition === null || ! in_array($definition->kind, ['mcp', 'api'], true) || $original === null
                || ($original['executor_reference'] ?? null) !== $definition->executorReference
                || ! is_string($toolSource['evidence_hash'] ?? null)
                || ! hash_equals($toolSource['evidence_hash'], hash('sha256', json_encode($original['result'] ?? [], JSON_UNESCAPED_UNICODE)))) {
                $unavailable++;
                continue;
            }
            $claims = array_values(array_filter((array) data_get($metadata, 'grounding.claims', []),
                fn ($claim) => is_array($claim) && ($claim['tool_execution_id'] ?? null) === $execution->id));
            // Original records may narrow a table attribution; never send them to a model
            // or display them as refreshed facts. Unused executions cannot enter this loop.
            if (! $this->matches($ids, $claims, EvidenceProjector::records($original))) {
                continue;
            }
            $key = 'tool:'.$execution->id;
            $sources[$key] = ['kind' => $definition->kind, 'execution_id' => $execution->id,
                'tool' => $definition->name, 'title' => $this->label($definition->displayName),
                'connector' => $this->label((string) ($definition->metadata['source_name'] ?? $definition->displayName)),
                'retrieved_at' => $execution->completed_at?->toIso8601String(), 'status' => 'authorized_historical_call', 'usage' => 'used'];
            $toolSources[$key] = array_intersect_key($toolSource, array_flip(['execution_id', 'tool', 'evidence_hash', 'retrieved_at']));
        }

        $it = str_starts_with($locale, 'it');
        $lines = [];
        if ($sources !== []) {
            $lines[] = $it ? '## Fonti della risposta precedente' : '## Sources for the previous answer';
            foreach ($sources as $source) {
                $kind = match ($source['kind']) { 'email' => 'Email', 'mcp' => 'MCP', 'api' => 'API', default => $it ? 'Documento' : 'Document' };
                $line = '- **'.$kind.'** — '.$source['title'];
                if (isset($source['tool'])) {
                    if ($source['connector'] !== $source['title']) {
                        $line .= ' · '.$source['connector'];
                    }
                    $line .= ' (`'.$source['tool'].'`)';
                    if ($source['retrieved_at'] !== null) {
                        $line .= ($it ? ', consultato il ' : ', retrieved at ').$source['retrieved_at'];
                    }
                }
                $lines[] = $line;
            }
            $lines[] = $it ? 'Questi sono i riferimenti registrati per quella risposta. Non ho effettuato una nuova ricerca né aggiornato i dati dei connettori.'
                : 'These are the references recorded for that answer. I have not run a new search or refreshed connector data.';
        } else {
            $lines[] = $previous === null ? ($it ? 'Non c’è ancora una risposta precedente di cui mostrare le fonti.' : 'There is no previous answer to attribute yet.')
                : ($unavailable > 0 ? ($it ? 'Le fonti registrate per la risposta precedente non sono attualmente consultabili con i permessi e i filtri attivi.' : 'The recorded sources for the previous answer are not currently accessible with the active permissions and filters.')
                    : ($it ? 'Non ho riferimenti alle fonti registrati per questa risposta'.($ids === [] ? '.' : ' e per il riferimento richiesto.') : 'I have no recorded source attribution for this answer'.($ids === [] ? '.' : ' and the requested reference.')));
        }
        if ($unavailable > 0 && $sources !== []) {
            $lines[] = $it ? 'Alcuni riferimenti non sono più consultabili; mostro soltanto quelli ancora autorizzati.' : 'Some references are no longer accessible; only currently authorized sources are shown.';
        }
        return new AgentAnswer(implode("\n\n", $lines), $locale, $sources !== [] && $unavailable === 0 ? 'complete' : 'partial',
            array_values($citations), array_values($toolSources), $unavailable > 0 ? ['provenance_unavailable'] : [],
            grounding: ['status' => 'provenance', 'reason' => 'answer_provenance', 'claims' => [],
                'semantic_validation' => ['used' => false, 'reason' => 'deterministic_attribution'],
                'provenance' => ['answer_id' => $previous?->getKey(), 'answer_created_at' => $previous?->created_at?->toIso8601String(),
                    'recorded_references' => $recorded, 'unavailable_references' => $unavailable,
                    'sources' => array_values($sources), 'new_searches' => 0, 'new_tool_calls' => 0]]);
    }

    private function matches(array $identifiers, array ...$groups): bool
    {
        if ($identifiers === []) {
            return true;
        }
        foreach ($groups as $entries) {
            foreach ($entries as $entry) {
                $text = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                foreach ($identifiers as $identifier) {
                    if (EvidenceProjector::contains($text, $identifier)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /** Titles/tool labels are untrusted display data, not Markdown or instructions. */
    private function label(string $value): string
    {
        $value = preg_replace('/\s+/u', ' ', $this->masker->maskString(strip_tags($value)));
        return preg_replace('/([\\\\`*_{}\[\]()#+.!|>~])/u', '\\\\$1', mb_substr(trim($value), 0, 240));
    }
}

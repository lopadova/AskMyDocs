<?php

declare(strict_types=1);

namespace App\Agent;

use App\Agent\Evidence\AgentEvidenceAccess;
use App\Agent\Grounding\AgentClaimGroundingValidator;
use App\Models\AgentRun;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/** Durable accepted facts, saved before any optional rewrite; recovery never calls a model. */
final class VerifiedAnswerCheckpoint
{
    public function save(AgentRun $run, AgentAnswer $answer, array $evidence): void
    {
        if (($answer->grounding['status'] ?? '') !== 'grounded' || ($answer->grounding['claims'] ?? []) === []
            || app(\App\Support\TenantContext::class)->current() !== $run->tenant_id) {
            return;
        }
        DB::transaction(function () use ($run, $answer, $evidence): void {
            $locked = AgentRun::query()->forTenant($run->tenant_id)->whereKey($run->id)->lockForUpdate()->firstOrFail();
            if ($locked->isTerminal()) return;
            $locked->forceFill(['result_json' => [...($locked->result_json ?? []), 'verified_answer_checkpoint' => [
                'context' => AgentExecutionContext::fromArray($locked->toArray())->jsonSerialize(),
                'question_hash' => hash('sha256', (string) data_get($locked->input_json, 'question', '')),
                'destination' => $this->destination($locked),
                'answer' => $answer->jsonSerialize(), 'evidence' => $evidence, 'saved_at' => now()->toIso8601String(),
            ]]])->save();
        });
    }

    public function recover(AgentRun $run, string $reason): bool
    {
        $previousTenant = app(\App\Support\TenantContext::class)->current();
        $previousPackageTenant = app(\Padosoft\AskMyDocsConnectorBase\Support\TenantContext::class)->current();
        $previousLocale = App::currentLocale();
        $previousTimezone = date_default_timezone_get();
        $previousUser = Auth::user();
        try {
            $run->refresh();
            if (! in_array($run->status, [AgentRun::STATUS_RUNNING, AgentRun::STATUS_QUEUED], true)) return false;
            $snapshot = data_get($run->result_json, 'verified_answer_checkpoint');
            if (! is_array($snapshot)) return false;
            $checkedInput = $run->input_json;
            $checkedResult = $run->result_json;
            $context = AgentExecutionContext::fromArray($run->toArray());
            $context->assertMatches($run);
            if (($snapshot['context'] ?? null) !== $context->jsonSerialize()
                || ($snapshot['question_hash'] ?? null) !== hash('sha256', (string) data_get($run->input_json, 'question', ''))
                || ($snapshot['destination'] ?? null) !== $this->destination($run)) return false;
            $context->activate();
            Auth::forgetGuards();
            if ($run->actor_type === 'user' && ($run->user === null || ! $run->user->is_active)) {
                return false;
            }
            if ($run->user !== null) {
                if (! $run->user->is_active) {
                    return false;
                }
                Auth::setUser($run->user);
            }
            $safe = app(AgentEvidenceAccess::class)->current($run, $context, $snapshot['evidence'] ?? [],
                data_get($run->result_json, 'question_understanding.transition') === 'recap');
            // Recovery cannot reconsider semantics. Require the same complete
            // source window, rather than accepting an old excerpt inside changed context.
            $filters = app(AgentRetrievalFiltersFactory::class)->forRun($run, $context);
            foreach ($safe['documents'] as &$document) {
                $originalDocument = collect($snapshot['evidence']['documents'] ?? [])
                    ->first(fn ($item) => ($item['document_id'] ?? null) === ($document['document_id'] ?? null));
                $document['evidence'] = array_values(array_filter($document['evidence'] ?? [], function ($chunk) use ($document, $originalDocument, $run, $context, $filters) {
                    $originalChunk = collect($originalDocument['evidence'] ?? [])->first(fn ($item) =>
                        ($item['chunk_id'] ?? null) === ($chunk['chunk_id'] ?? null)
                        && ($item['content'] ?? null) === ($chunk['content'] ?? null)
                        && ($item['evidence_hash'] ?? null) === ($chunk['evidence_hash'] ?? null));
                    if ($originalChunk === null) return false;
                    $source = app(\App\Services\Kb\Investigation\KbSourceReader::class)->readCandidate([
                        'document' => ['id' => $document['document_id']], 'chunk_id' => $chunk['chunk_id'] ?? 0], $context->projectKey, $run->user, $filters);
                    if ($source === null) return false;
                    $masker = app(\App\Services\Widget\WidgetPiiMasker::class);
                    return $masker->maskString($source['excerpt']) === ($originalChunk['content'] ?? null)
                        && $masker->maskString($source['title']) === ($originalChunk['source_title'] ?? $originalDocument['title'] ?? null);
                }));
            }
            unset($document);
            $claims = [];
            foreach (data_get($snapshot, 'answer.grounding.claims', []) as $claim) {
                $validated = app(AgentClaimGroundingValidator::class)->validate('', $safe, [$claim]);
                if ($validated['valid']) $claims[] = $validated['claims'][0];
            }
            if ($claims === []) return false;
            $notes = app(AgentSourceLimitations::class)->forClaims($safe['api_tools'], $claims, $context->locale);
            $notice = str_starts_with($context->locale, 'it')
                ? 'La ricerca si è interrotta prima della rifinitura. Queste sono le informazioni già verificate.'
                : 'Research stopped before final refinement. These are the findings already verified.';
            $answer = new AgentAnswer(implode("\n\n", [...array_column($claims, 'text'), ...array_column($notes, 'text'), $notice]),
                data_get($snapshot, 'answer.locale', $context->locale), 'partial',
                $this->citations($safe['documents'], $claims),
                $this->toolSources($safe['api_tools'], $claims),
                [$notice], grounding: ['status' => 'grounded', 'claims' => $claims,
                    'source_excerpts' => [], 'recovery' => ['reason' => in_array($reason, ['worker_timeout', 'agent_run_failed'], true) ? $reason : 'agent_run_failed']]);
            return DB::transaction(function () use ($run, $answer, $snapshot, $checkedInput, $checkedResult): bool {
                $locked = AgentRun::query()->forTenant($run->tenant_id)->whereKey($run->id)->lockForUpdate()->firstOrFail();
                if (! in_array($locked->status, [AgentRun::STATUS_RUNNING, AgentRun::STATUS_QUEUED], true)
                    || $locked->input_json !== $checkedInput || $locked->result_json !== $checkedResult
                    || data_get($locked->result_json, 'verified_answer_checkpoint') !== $snapshot
                    || ($snapshot['context'] ?? null) !== AgentExecutionContext::fromArray($locked->toArray())->jsonSerialize()
                    || ($snapshot['question_hash'] ?? null) !== hash('sha256', (string) data_get($locked->input_json, 'question', ''))
                    || ($snapshot['destination'] ?? null) !== $this->destination($locked)) return false;
                $locked->forceFill(['status' => AgentRun::STATUS_PARTIAL, 'completed_at' => now(), 'error_code' => null,
                    'result_json' => [...($locked->result_json ?? []), 'phase' => 'final', 'decision' => 'partial',
                        'stop_reason' => 'verified_answer_recovered', 'response' => $answer->jsonSerialize()]])->save();
                app(AgentResultProjector::class)->project($locked, $answer);
                app(AgentEventPublisher::class)->publish($locked, 'run.partial', 'run.partial', data: ['response' => $answer->jsonSerialize()], canCancel: false);
                return true;
            });
        } catch (\Throwable) {
            return false;
        } finally {
            Auth::forgetGuards();
            if ($previousUser !== null) Auth::setUser($previousUser);
            app(\App\Support\TenantContext::class)->set($previousTenant);
            app(\Padosoft\AskMyDocsConnectorBase\Support\TenantContext::class)->set($previousPackageTenant);
            App::setLocale($previousLocale);
            date_default_timezone_set($previousTimezone);
        }
    }
    private function citations(array $documents, array $claims): array
    {
        $citations = [];
        foreach ($documents as $document) {
            $selected = array_values(array_filter($claims, fn ($claim) =>
                $claim['document_id'] === ($document['document_id'] ?? null)));
            if ($selected === []) {
                continue;
            }
            $chunks = [];
            foreach ($selected as $claim) {
                foreach ($document['evidence'] ?? [] as $chunk) {
                    if (($chunk['evidence_hash'] ?? null) === $claim['evidence_hash']) {
                        $chunks[] = ['chunk_id' => $chunk['chunk_id'] ?? null, 'heading' => null,
                            'snippet' => $claim['quote'], 'evidence_hash' => $claim['evidence_hash']];
                        break;
                    }
                }
            }
            $citations[] = ['document_id' => $document['document_id'], 'title' => $document['title'] ?? null,
                'chunks' => $chunks, 'claims' => array_map(fn ($claim) => [
                    'text' => $claim['text'], 'quote' => $claim['quote'], 'evidence_hash' => $claim['evidence_hash'],
                ], $selected)];
        }
        return $citations;
    }

    private function toolSources(array $tools, array $claims): array
    {
        $ids = array_column($claims, 'tool_execution_id');
        return array_values(array_map(fn ($tool) => [
            'execution_id' => $tool['execution_id'], 'tool' => $tool['tool'] ?? null,
            'display_name' => $tool['display_name'] ?? null, 'kind' => $tool['kind'] ?? null,
            'evidence_hash' => $tool['evidence_hash'] ?? null, 'retrieved_at' => $tool['retrieved_at'] ?? null,
        ], array_filter($tools, fn ($tool) => in_array($tool['execution_id'] ?? null, $ids, true))));
    }

    private function destination(AgentRun $run): array
    {
        return [
            'user_id' => $run->user_id,
            'conversation_id' => $run->conversation_id,
            'widget_session_id' => $run->widget_session_id,
            'widget_identity_id' => $run->widget_identity_id,
        ];
    }
}

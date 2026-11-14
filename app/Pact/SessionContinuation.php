<?php

declare(strict_types=1);

namespace App\Pact;

use App\Agent\AgentExecutionContext;
use App\Agent\AgentRunDispatcher;
use App\Agent\Budget\AgentBudgetTracker;
use App\Agent\Evidence\AgentEvidenceEnvelope;
use App\Agent\Tools\AgentToolDefinition;
use App\Models\AgentRun;
use App\Pact\Jobs\ObserveSession;
use App\Services\Widget\WidgetPiiMasker;
use Illuminate\Support\Facades\DB;

/** Durable, bounded child conversations for an authenticated Federation task. */
final class SessionContinuation
{
    public function attach(AgentRun $parent, AgentExecutionContext $context, array $arguments, array $handoff): void
    {
        $question = trim((string) ($arguments['question'] ?? ''));
        if ($question === '' || mb_strlen($question) > 4000 || ! is_numeric($arguments['session_id'] ?? null)) throw new \DomainException('invalid_pact_session_request');
        DB::transaction(function () use ($parent, $context, $arguments, $handoff, $question): void {
            $owner = AgentRun::query()->forTenant($context->tenantId)->whereKey($parent->id)->lockForUpdate()->firstOrFail();
            if ($owner->isTerminal() || data_get($owner->input_json, 'pact_session_child')) throw new \DomainException('pact_session_parent_unavailable');
            $existing = DB::table('pact_session_handoffs')->where('parent_run_id', $owner->id)->where('action_id', $handoff['action_id'])->first();
            if ($existing) {
                $saved = json_decode($existing->handoff, true, 512, JSON_THROW_ON_ERROR);
                if (($saved['arguments'] ?? null) !== $arguments || (int) $existing->execution_id !== (int) $handoff['execution_id']) throw new \DomainException('pact_session_idempotency_conflict');
                return;
            }
            if (data_get($owner->result_json, 'pact_session_pending')) throw new \DomainException('pact_session_already_pending');
            $session = app(PrivateSessions::class)->query($context, $owner)->whereKey((int) $arguments['session_id'])->lockForUpdate()->firstOrFail();
            $busy = AgentRun::query()->forTenant($context->tenantId)->where('conversation_id', $session->id)->whereNull('input_json->research_parent_id')
                ->whereNotIn('status', [AgentRun::STATUS_COMPLETED, AgentRun::STATUS_PARTIAL, AgentRun::STATUS_FAILED, AgentRun::STATUS_CANCELLED])->exists();
            if ($busy) throw new \DomainException('pact_session_busy');
            $user = $owner->user;
            $childContext = new AgentExecutionContext((string) \Illuminate\Support\Str::uuid(), $context->tenantId, $context->projectKey, 'chat', 'user', $context->actorId, $context->locale, $context->timezone);
            $message = $session->messages()->create(['tenant_id' => $context->tenantId, 'role' => 'user', 'content' => $question, 'metadata' => ['origin' => 'pact']]);
            $child = app(AgentRunDispatcher::class)->create($childContext, ['question' => $question, 'filters' => [], 'user_message_id' => $message->id,
                'pact_task_id' => data_get($owner->input_json, 'pact_task_id'), 'pact_session_child' => true], ['user_id' => $user->id, 'conversation_id' => $session->id]);
            $message->forceFill(['metadata' => ['origin' => 'pact', 'agent_run_id' => $child->run_id]])->save();
            $id = DB::table('pact_session_handoffs')->insertGetId(['tenant_id' => $context->tenantId, 'parent_run_id' => $owner->id, 'child_run_id' => $child->id,
                'conversation_id' => $session->id, 'execution_id' => $handoff['execution_id'], 'action_id' => $handoff['action_id'],
                'handoff' => json_encode($handoff + ['arguments' => $arguments], JSON_THROW_ON_ERROR), 'status' => 'waiting', 'created_at' => now(), 'updated_at' => now()]);
            $checkpoint = (array) $owner->result_json;
            $checkpoint['pact_session_pending'] = $id;
            $owner->forceFill(['status' => AgentRun::STATUS_WAITING_API_TOOL, 'result_json' => $checkpoint])->save();
            DB::afterCommit(function () use ($child, $id): void {
                try { app(AgentRunDispatcher::class)->enqueue($child); } catch (\Throwable) { /* The durable handoff is recovered on polling. */ }
                try { ObserveSession::dispatch($id)->delay(now()->addSeconds(2)); } catch (\Throwable) { /* The durable handoff is recovered on polling. */ }
            });
        }, 3);
        $parent->refresh();
    }

    /** Called at child execution and before each paid provider call. */
    public function authorizeChild(AgentRun $child): void
    {
        if (! data_get($child->input_json, 'pact_session_child')) return;
        $researchParent = data_get($child->input_json, 'research_parent_id');
        if ($researchParent !== null) {
            $root = AgentRun::query()->forTenant($child->tenant_id)->find($researchParent);
            if (! $root || ! data_get($root->input_json, 'pact_session_child') || data_get($root->input_json, 'research_parent_id') !== null
                || $root->user_id !== $child->user_id || $root->conversation_id !== $child->conversation_id || $root->project_key !== $child->project_key
                || data_get($root->input_json, 'pact_task_id') !== data_get($child->input_json, 'pact_task_id')) throw new \DomainException('pact_session_scope_mismatch');
            $child = $root;
        }
        $row = DB::table('pact_session_handoffs')->where('child_run_id', $child->id)->where('status', 'waiting')->first();
        $parent = $row ? AgentRun::query()->forTenant($child->tenant_id)->find($row->parent_run_id) : null;
        if (! $parent || $parent->isTerminal() || $parent->status !== AgentRun::STATUS_WAITING_API_TOOL || (int) data_get($parent->result_json, 'pact_session_pending') !== (int) $row->id) throw new \DomainException('pact_session_parent_unavailable');
        $context = AgentExecutionContext::fromArray($parent->toArray());
        if (! app(PrivateSessions::class)->query($context, $parent)->whereKey($row->conversation_id)->exists() || (int) $child->conversation_id !== (int) $row->conversation_id || (string) $child->user_id !== (string) $parent->user_id || $child->project_key !== $parent->project_key || data_get($child->input_json, 'pact_task_id') !== data_get($parent->input_json, 'pact_task_id')) throw new \DomainException('pact_session_scope_mismatch');
    }

    /** Returns true while observation should be retried. Safe under duplicate deliveries. */
    public function observe(int $id): bool
    {
        return DB::transaction(function () use ($id): bool {
            $row = DB::table('pact_session_handoffs')->where('id', $id)->lockForUpdate()->first();
            if (! $row || $row->status !== 'waiting') return false;
            $parent = AgentRun::query()->forTenant($row->tenant_id)->whereKey($row->parent_run_id)->lockForUpdate()->first();
            $child = AgentRun::query()->forTenant($row->tenant_id)->whereKey($row->child_run_id)->lockForUpdate()->first();
            if (! $parent || $parent->isTerminal() || ! $child) { $this->close($row, $child); return false; }
            try { $this->authorizeChild($child); } catch (\Throwable) {
                $parent->forceFill(['status' => AgentRun::STATUS_FAILED, 'error_code' => 'unauthorized', 'completed_at' => now()])->save();
                $this->close($row, $child); return false;
            }
            $interactive = in_array($child->status, [AgentRun::STATUS_AWAITING_CONFIRMATION, AgentRun::STATUS_AWAITING_MCP_CONFIRMATION, AgentRun::STATUS_AWAITING_MCP_INPUT, AgentRun::STATUS_WAITING_MCP_TASK], true);
            if (! $child->isTerminal() && ! $interactive) return true;
            $handoff = json_decode($row->handoff, true, 512, JSON_THROW_ON_ERROR);
            $answer = data_get($child->result_json, 'response.answer');
            $success = in_array($child->status, [AgentRun::STATUS_COMPLETED, AgentRun::STATUS_PARTIAL], true) && is_string($answer) && $answer !== '';
            $body = $success ? ['answer' => $answer, 'session_id' => (string) $row->conversation_id, 'partial' => $child->status === AgentRun::STATUS_PARTIAL] : ['error' => $interactive ? 'session_interaction_required' : 'session_run_'.$child->status];
            if ($interactive) $child->forceFill(['status' => AgentRun::STATUS_CANCELLED, 'cancelled_at' => now(), 'completed_at' => now()])->save();
            $safe = app(WidgetPiiMasker::class)->maskArray($body) ?? [];
            $checkpoint = (array) $parent->result_json;
            $execution = $parent->toolExecutions()->whereKey($row->execution_id)->firstOrFail();
            $execution->forceFill(['status' => $success ? 'completed' : 'failed', 'result_meta_json' => array_merge((array) $execution->result_meta_json, ['result' => $safe, 'complete' => $success]), 'error_code' => $success ? null : 'session_run_failed', 'physical_request_count' => 0, 'completed_at' => now()])->save();
            $tool = new AgentToolDefinition('ask_private_session', 'Ask own session', '', 'catalog', [], false, false, 0, 0, 0, 'ask_private_session');
            $evidence = new AgentEvidenceEnvelope(app(WidgetPiiMasker::class));
            $evidence->import((array) ($checkpoint['evidence'] ?? []));
            $evidence->addToolResult($tool, $handoff['arguments'], $safe, $execution->id);
            if (! $success) $evidence->addWarning('session_run_failed', 'ask_private_session');
            $checkpoint['evidence'] = $evidence->jsonSerialize();
            $checkpoint['completed_actions'][] = ['id' => $handoff['action_id'], 'execution_id' => $execution->id, 'tool' => $tool->name, 'kind' => 'catalog', 'purpose' => $handoff['purpose'], 'status' => $success ? 'completed' : 'failed', 'result' => $safe];
            $checkpoint['action_results'][$handoff['action_id']] = $safe;
            unset($checkpoint['pact_session_pending']);
            $checkpoint['api_tool_resumptions']['pact-session-'.$id] = true;
            $parent->forceFill(['status' => AgentRun::STATUS_QUEUED, 'result_json' => $checkpoint])->save();
            (new AgentBudgetTracker($parent))->recordResult(0, strlen(json_encode($safe, JSON_THROW_ON_ERROR)), $success);
            DB::table('pact_session_handoffs')->where('id', $id)->update(['status' => 'completed', 'updated_at' => now()]);
            DB::afterCommit(function () use ($parent): void {
                try { app(AgentRunDispatcher::class)->enqueue($parent); } catch (\Throwable) { /* The completed handoff is recovered on polling. */ }
            });
            return false;
        }, 3);
    }

    public function cancel(AgentRun $parent): void
    {
        foreach (DB::table('pact_session_handoffs')->where('parent_run_id', $parent->id)->where('status', 'waiting')->get() as $row) $this->observe((int) $row->id);
    }

    /** The durable handoff also permits recovery if either queue dispatch was lost. */
    public function recover(AgentRun $parent): void
    {
        $pending = data_get($parent->result_json, 'pact_session_pending');
        if (is_numeric($pending)) {
            $row = DB::table('pact_session_handoffs')->where('id', $pending)->where('parent_run_id', $parent->id)->first();
            $child = $row ? AgentRun::query()->forTenant($parent->tenant_id)->find($row->child_run_id) : null;
            if ($child && $child->status === AgentRun::STATUS_QUEUED) {
                $this->authorizeChild($child);
                app(AgentRunDispatcher::class)->enqueue($child);
            }
            $this->observe((int) $pending);
        } elseif ($parent->status === AgentRun::STATUS_QUEUED && DB::table('pact_session_handoffs')->where('parent_run_id', $parent->id)->where('status', 'completed')->exists()) {
            app(AgentRunDispatcher::class)->enqueue($parent);
        }
    }

    private function close(object $row, ?AgentRun $child): void
    {
        if ($child && ! $child->isTerminal()) $child->forceFill(['status' => AgentRun::STATUS_CANCELLED, 'cancelled_at' => now(), 'completed_at' => now()])->save();
        DB::table('pact_session_handoffs')->where('id', $row->id)->update(['status' => 'cancelled', 'updated_at' => now()]);
    }
}

<?php

declare(strict_types=1);

namespace App\Pact\Native;

use App\Agent\AgentChatTurnStarter;
use App\Agent\AgentExecutionContextFactory;
use App\Agent\AgentRunControl;
use App\Compliance\TenantContextBridge;
use App\Models\AgentRun;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Chat\ChatInputRedactor;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PactLaravel\Contracts\AgentHandler;
use PactLaravel\Execution\AgentContext;
use PactLaravel\Execution\AgentResult;
use PactLaravel\Models\Task;
use PactLaravel\Runtime\Store;
use Padosoft\AiActCompliance\MultiTenancy\Services\TenantContext as ComplianceTenant;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext as ConnectorTenant;
use Ramsey\Uuid\Uuid;

/** Hands PACT turns to the application queue without holding a PACT worker lease. */
final readonly class ChatHandler implements AgentHandler
{
    private const ANSWER_URI = 'https://github.com/bramato/Pact/blob/main/sdk/laravel/resources/contracts/answer.json';
    private const ANSWER_DIGEST = '35599801c0eaecab86245e2744955d0727ad250fb62b03bb4b4da087512efb44';

    public function __construct(
        private SubjectResolver $subjects,
        private TaskAccess $access,
        private Store $store,
        private AgentChatTurnStarter $turns,
        private AgentExecutionContextFactory $contexts,
        private AgentRunControl $control,
        private ChatInputRedactor $redactor,
    ) {}

    public function handle(AgentContext $context): AgentResult
    {
        $task = Task::query()->where('user_id', $context->userId)->where('federation_id', $context->federationId)->find($context->taskId);
        $subject = $this->subjects->find($context->userId);
        $user = $subject instanceof Subject ? $subject->authorizedUser() : null;
        if ($task?->state !== 'TASK_STATE_WORKING' || ! $subject instanceof Subject || $user === null || ! $this->access->permits($task)) {
            return $this->error('AUTHORIZATION', 'Il collegamento o l’accesso al progetto non è più attivo.');
        }

        $question = trim((string) ($context->input['message'] ?? ''));
        if ($question === '') {
            return $this->error('INVALID_INPUT', 'Inserisci una domanda.');
        }
        $initialId = $this->runId($context, 'initial');
        $previous = $this->findRun($subject, $context->taskId, (string) ($context->checkpoint['host_run'] ?? $initialId));
        $clarification = $context->checkpoint['host_question'] ?? null;
        if (is_string($clarification) && array_key_exists($clarification, $context->answers)) {
            $answer = $context->answers[$clarification]['text'] ?? null;
            if ($previous === null || ! is_string($answer) || trim($answer) === '' || mb_strlen($answer) > 2000) {
                return $this->error('INVALID_ANSWER', 'Il chiarimento deve contenere da 1 a 2000 caratteri.');
            }
            $run = $this->start($context, $subject, $user, $this->runId($context, $clarification), trim($answer), $previous);
        } else {
            $run = $previous ?? $this->start($context, $subject, $user, $initialId, $question);
        }

        return $this->observe($context, $run);
    }

    private function start(AgentContext $context, Subject $subject, User $user, string $id, string $question, ?AgentRun $previous = null): AgentRun
    {
        return $this->store->atomic(function () use ($context, $subject, $user, $id, $question, $previous): AgentRun {
            $task = Task::query()->where('user_id', $context->userId)->lockForUpdate()->findOrFail($context->taskId);
            if ($task->state !== 'TASK_STATE_WORKING' || ! $this->access->permits($task)) {
                throw new \DomainException('The PACT task is no longer active.');
            }
            return DB::transaction(function () use ($context, $subject, $user, $id, $question, $previous): AgentRun {
                $existing = $this->findRun($subject, $context->taskId, $id);
                if ($existing !== null) {
                    return $existing;
                }
                $tenant = app(TenantContext::class);
                $connector = app(ConnectorTenant::class);
                $compliance = app(ComplianceTenant::class);
                $priorTenant = $tenant->current();
                $priorConnector = $connector->current();
                $priorCompliance = $compliance->current();
                try {
                    $tenant->set($subject->tenant_id);
                    $connector->set($subject->tenant_id);
                    app(TenantContextBridge::class)->syncFromHost();
                    $conversation = $previous === null
                        ? $user->conversations()->create(['tenant_id' => $subject->tenant_id, 'project_key' => $subject->project_key, 'title' => 'Incarico PACT'])
                        : Conversation::query()->forTenant($subject->tenant_id)->where('user_id', $user->id)
                            ->where('project_key', $subject->project_key)->findOrFail($previous->conversation_id);
                    $text = $this->redactor->redact($question);
                    $execution = $this->contexts->forUser($user, $subject->project_key, runId: $id);
                    return $this->turns->start($execution, $conversation, $user, $text, [
                        'question' => $text,
                        'pact_client_task_id' => $context->taskId,
                        'pact_client_invocation_id' => $context->invocationId,
                        'session_evidence' => (bool) config('agent.waves.enabled', true),
                    ], ['origin' => 'pact'])->run;
                } finally {
                    $tenant->set($priorTenant);
                    $connector->set($priorConnector);
                    $compliance->set($priorCompliance);
                }
            });
        });
    }

    private function observe(AgentContext $context, AgentRun $run): AgentResult
    {
        $checkpoint = ['host_run' => $run->run_id, 'host_question' => null];
        if ($run->isTerminal()) {
            if (in_array($run->status, [AgentRun::STATUS_FAILED, AgentRun::STATUS_CANCELLED], true)) {
                return $this->error('EXECUTION_FAILED', 'L’incarico AskMyDocs non è stato completato.');
            }
            $response = data_get($run->result_json, 'response', []);
            $text = $response['answer'] ?? null;
            if (! is_string($text) || trim($text) === '') {
                return $this->error('EXECUTION_FAILED', 'L’incarico AskMyDocs non ha prodotto una risposta.');
            }
            $reason = data_get($response, 'grounding.reason');
            if (in_array($reason, ['question_interpretation_unavailable', 'context_evidence_unavailable'], true)) {
                return $this->error('AGENT_UNAVAILABLE', $text, retryable: true);
            }
            if ($reason === 'focus_ambiguous') {
                $id = 'clarify-'.$run->run_id;

                return $this->question($context, $id, $text.' Rispondi con un oggetto che contiene il campo text.',
                    ['host_run' => $run->run_id, 'host_question' => $id]);
            }

            return AgentResult::completed(['text' => $text, 'sources' => array_values(array_filter($response['citations'] ?? [], 'is_array'))]);
        }
        if ($run->status === AgentRun::STATUS_AWAITING_CONFIRMATION) {
            $sequence = $run->events()->where('type', 'run.awaiting_confirmation')->max('sequence');
            $id = 'budget-'.$run->run_id.'-'.$sequence;
            $confirmation = $context->answers[$id]['approved'] ?? null;
            if ($confirmation === false) {
                $this->control->cancel($run);

                return $this->error('BUDGET_DECLINED', 'La ricerca non è stata autorizzata a proseguire.');
            }
            if (array_key_exists($id, $context->answers) && $confirmation !== true) {
                return $this->error('INVALID_ANSWER', 'La risposta deve contenere il campo booleano approved.');
            }
            if ($confirmation !== true) {
                return $this->question($context, $id, 'Vuoi estendere il budget di ricerca? Rispondi con un oggetto che contiene il campo booleano approved.', $checkpoint);
            }
            try {
                DB::transaction(fn () => $this->control->resume(
                    $run,
                    (int) data_get($run->budget_json, 'requested_extension.logical', 0),
                    (int) data_get($run->budget_json, 'requested_extension.physical', 1),
                ));
            } catch (\DomainException $exception) {
                if ($exception->getMessage() !== 'run_not_awaiting_confirmation') {
                    throw $exception;
                }
            }
        } elseif (in_array($run->status, [AgentRun::STATUS_AWAITING_MCP_CONFIRMATION, AgentRun::STATUS_AWAITING_MCP_INPUT, AgentRun::STATUS_WAITING_MCP_TASK], true)) {
            $this->control->cancel($run);

            return $this->error('INTERACTION_REQUIRED', 'La fonte richiede un’interazione da completare in AskMyDocs.');
        }
        $this->store->atomic(function () use ($context): void {
            $job = $this->store->enqueue('execute', $context->taskId, [], 'askmydocs.poll:'.$context->invocationId);
            if ($job->wasRecentlyCreated) {
                $job->update(['available_at' => now()->addSeconds(3)]);
            }
        });

        return AgentResult::waiting($checkpoint);
    }

    private function question(AgentContext $context, string $id, string $prompt, array $checkpoint): AgentResult
    {
        return AgentResult::question([[
            'question_id' => $id, 'task_id' => $context->taskId, 'prompt' => Str::limit($prompt, 10000, ''),
            'answer_schema' => ['schema_uri' => self::ANSWER_URI, 'sha256' => self::ANSWER_DIGEST],
            'required' => true, 'expires_at' => now()->addMinutes(10)->toIso8601ZuluString(), 'status' => 'open',
        ]], $checkpoint);
    }

    private function findRun(Subject $subject, string $taskId, string $id): ?AgentRun
    {
        return AgentRun::query()->forTenant($subject->tenant_id)->where('user_id', $subject->user_id)
            ->where('project_key', $subject->project_key)->where('input_json->pact_client_task_id', $taskId)
            ->where('run_id', $id)->first();
    }

    private function runId(AgentContext $context, string $turn): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, 'askmydocs/pact/'.$context->taskId.'/'.$turn)->toString();
    }

    private function error(string $code, string $message, bool $retryable = false): AgentResult
    {
        return AgentResult::error(['code' => $code, 'message' => $message, 'retryable' => $retryable]);
    }
}

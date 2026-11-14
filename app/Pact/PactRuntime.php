<?php

declare(strict_types=1);

namespace App\Pact;

use App\Agent\AgentChatTurnStarter;
use App\Compliance\TenantContextBridge;
use App\Agent\AgentExecutionContextFactory;
use App\Agent\AgentRunControl;
use App\Models\AgentRun;
use App\Models\Conversation;
use App\Models\User;
use App\Support\TenantContext;
use Padosoft\PactAgent\Contracts\PollableRuntime;
use Padosoft\PactAgent\Contracts\Runtime;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext as ConnectorContext;
use Padosoft\AiActCompliance\MultiTenancy\Services\TenantContext as ComplianceContext;

/** Creates one private host conversation per PACT task and polls its durable AgentRun. */
final class PactRuntime implements Runtime, PollableRuntime
{
    private ?string $previousTenant = null;
    private ?string $previousConnector = null;
    private mixed $previousCompliance = null;
    private bool $hadCompliance = false;

    public function start(array $task): array
    {
        $this->tenant($task['tenant_id']);
        $user = User::query()->findOrFail($task['user_id']);
        $project = $task['payload']['principal_context']['project_key'];
        $message = $task['payload']['input']['message'];
        $conversation = $user->conversations()->create(['tenant_id' => $task['tenant_id'], 'project_key' => $project, 'title' => null]);
        $context = app(AgentExecutionContextFactory::class)->forUser($user, $project);
        $turn = app(AgentChatTurnStarter::class)->start($context, $conversation, $user, $message, ['question' => $message, 'filters' => []]);
        return ['state' => 'running', 'checkpoint' => ['conversation_id' => $conversation->id, 'run_id' => $turn->run->run_id]];
    }

    public function poll(array $task): array
    {
        $this->tenant($task['tenant_id']);
        $checkpoint = $task['checkpoint'];
        $run = AgentRun::query()->forTenant($task['tenant_id'])->where('run_id', $checkpoint['run_id'])->where('user_id', $task['user_id'])->where('conversation_id', $checkpoint['conversation_id'])->firstOrFail();
        if (in_array($run->status, [AgentRun::STATUS_FAILED, AgentRun::STATUS_CANCELLED], true)) return ['state' => 'failed', 'checkpoint' => $checkpoint];
        if (in_array($run->status, [AgentRun::STATUS_COMPLETED, AgentRun::STATUS_PARTIAL], true)) {
            $response = data_get($run->result_json, 'response', []);
            $text = $response['answer'] ?? null;
            if (! is_string($text) || $text === '') return ['state' => 'failed', 'checkpoint' => $checkpoint];
            $sources = array_values(array_filter($response['citations'] ?? [], 'is_array'));
            return ['state' => 'completed', 'checkpoint' => $checkpoint + ['output' => ['text' => $text, 'sources' => $sources]]];
        }
        return ['state' => 'running', 'checkpoint' => $checkpoint];
    }

    public function resume(array $task, array $input): array
    {
        throw new \LogicException('Interactive continuation is not configured');
    }

    public function cancel(array $task): void
    {
        $this->tenant($task['tenant_id']);
        $id = $task['checkpoint']['run_id'] ?? null;
        if (! is_string($id)) return;
        $run = AgentRun::query()->forTenant($task['tenant_id'])->where('run_id', $id)->where('user_id', $task['user_id'])->first();
        if ($run !== null && ! $run->isTerminal()) app(AgentRunControl::class)->cancel($run);
    }

    public function reset(): void
    {
        if ($this->previousTenant !== null) app(TenantContext::class)->set($this->previousTenant);
        if ($this->previousConnector !== null) app(ConnectorContext::class)->set($this->previousConnector);
        if ($this->hadCompliance) app(ComplianceContext::class)->set($this->previousCompliance);
        $this->previousTenant = $this->previousConnector = null;
        $this->previousCompliance = null; $this->hadCompliance = false;
    }

    private function tenant(string $tenant): void
    {
        $context = app(TenantContext::class); $connector = app(ConnectorContext::class);
        $this->previousTenant ??= $context->current(); $this->previousConnector ??= $connector->current();
        if (! $this->hadCompliance && app()->bound(ComplianceContext::class)) {
            $this->previousCompliance = app(ComplianceContext::class)->current(); $this->hadCompliance = true;
        }
        $context->set($tenant); $connector->set($tenant);
        app(TenantContextBridge::class)->syncFromHost();
    }
}

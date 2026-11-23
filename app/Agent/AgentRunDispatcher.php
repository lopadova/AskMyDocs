<?php

declare(strict_types=1);

namespace App\Agent;

use App\Jobs\ExecuteAgentRunJob;
use App\Models\AgentRun;
use Illuminate\Support\Facades\DB;

final class AgentRunDispatcher
{
    /**
     * @param  array<string,mixed>  $input
     * @param  array{user_id?:int,conversation_id?:int,widget_identity_id?:int,widget_session_id?:int}  $links
     */
    public function dispatch(AgentExecutionContext $context, array $input, array $links = []): AgentRun
    {
        $run = DB::transaction(fn (): AgentRun => $this->create($context, $input, $links));

        $this->enqueue($run);

        return $run;
    }

    /**
     * Persist a run without choosing its execution transport. Realtime turns
     * use this seam to execute the exact same handler inline, while ordinary
     * chat turns continue through the durable queue.
     *
     * @param  array<string,mixed>  $input
     * @param  array{user_id?:int,conversation_id?:int,widget_identity_id?:int,widget_session_id?:int}  $links
     */
    public function create(AgentExecutionContext $context, array $input, array $links = []): AgentRun
    {
        return AgentRun::create([
            'run_id' => $context->runId,
            'tenant_id' => $context->tenantId,
            'project_key' => $context->projectKey,
            'user_id' => $links['user_id'] ?? null,
            'conversation_id' => $links['conversation_id'] ?? null,
            'widget_identity_id' => $links['widget_identity_id'] ?? null,
            'widget_session_id' => $links['widget_session_id'] ?? null,
            'channel' => $context->channel,
            'actor_type' => $context->actorType,
            'actor_id' => $context->actorId,
            'locale' => $context->locale,
            'timezone' => $context->timezone,
            'status' => AgentRun::STATUS_QUEUED,
            'input_json' => $input,
            'counters_json' => [],
        ]);
    }

    public function enqueue(AgentRun $run): void
    {
        if (is_string(data_get($run->input_json, 'pact_client_task_id'))) {
            // The shared queue can pick up the job as soon as it is published.
            $run->getConnection()->afterCommit(fn () => $this->push($run, pactClient: true));

            return;
        }

        $this->push($run, pactClient: false);
    }

    private function push(AgentRun $run, bool $pactClient): void
    {
        $diagnostics = app(\App\Agent\Debug\RunDiagnostics::class);
        $runtime = $diagnostics->runtime();
        $stage = $run->sessionStage();
        $sessionQueue = $stage !== null && $stage !== 'active'
            ? (string) config('agent.session_queue', 'agent-session') : null;
        $deliveryStage = $run->sessionDeliveryStage();
        $span = $diagnostics->begin($run, 'queue.wait', [
            'wait_for' => 'worker_receipt',
            'queue_connection' => $runtime['queue_connection'] ?? null,
            'queue_driver' => $runtime['queue_driver'] ?? null,
            'queue_name' => $sessionQueue ?? ($runtime['queue_name'] ?? null),
            'runtime' => $runtime,
        ]);
        try {
            // Force PendingDispatch destruction inside this try, so dispatch errors
            // are observed without changing Laravel's transport/after-commit policy.
            $pending = ExecuteAgentRunJob::dispatch($run->id, $run->tenant_id, $span, $deliveryStage);
            if ($sessionQueue !== null) {
                $pending->onQueue($sessionQueue);
            }
            if (! $pactClient && is_string(data_get($run->input_json, 'pact_task_id'))) {
                $pending->onConnection((string) config('pact-agent.connection', 'pact_agents'))->onQueue((string) config('pact-agent.queue', 'pact-agents'));
            }
            unset($pending);
        } catch (\Throwable $exception) {
            $diagnostics->failure($run, $span, $exception);
            if ($pactClient) {
                $this->failUndeliveredRun($run, $deliveryStage);
            }

            throw $exception;
        }
    }

    private function failUndeliveredRun(AgentRun $run, ?string $deliveryStage): void
    {
        $run->getConnection()->transaction(function () use ($run, $deliveryStage): void {
            $locked = AgentRun::query()->forTenant($run->tenant_id)->lockForUpdate()->findOrFail($run->id);
            if ($locked->status !== AgentRun::STATUS_QUEUED || $locked->sessionDeliveryStage() !== $deliveryStage) {
                return;
            }

            $locked->forceFill(['status' => AgentRun::STATUS_FAILED, 'error_code' => 'session_dispatch_failed', 'completed_at' => now()])->save();
            app(AgentEventPublisher::class)->publish($locked, 'run.failed', 'run.failed', canCancel: false);
        });
    }
}

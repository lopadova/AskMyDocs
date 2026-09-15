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
        ExecuteAgentRunJob::dispatch($run->id, $run->tenant_id);
    }
}

<?php

declare(strict_types=1);
namespace App\ApiTools;

use App\Ai\AiResponse;
use App\Ai\AgentChatModelScope;
use App\Models\AgentRun;
use Illuminate\Support\Facades\DB;

/** Opt-in provider-reported usage. No prompts, response bodies or guessed token counts. */
final class ToolMeasurements
{
    public function record(AiResponse $response,int $latencyMs): void
    {
        if (! config('api-tools.measurements_enabled',false)) { return; }
        $context=app(AgentChatModelScope::class)->context();
        if ($context === null) { return; }
        try {
            DB::table('api_tool_measurements')->insert(['tenant_id'=>$context->tenantId,'agent_run_id'=>$context->runId,
                'provider'=>$response->provider,'model'=>$response->model,'prompt_tokens'=>$response->promptTokens,
                'completion_tokens'=>$response->completionTokens,'latency_ms'=>max(0,$latencyMs),'created_at'=>now()]);
        } catch (\Throwable $e) { report($e); }
    }
    public function compare(AgentRun $before,AgentRun $after): array
    {
        if (! is_string(data_get($before->input_json,'question')) || $before->tenant_id !== $after->tenant_id || $before->project_key !== $after->project_key || $before->actor_id !== $after->actor_id
            || data_get($before->input_json,'question') !== data_get($after->input_json,'question') || ! $before->isTerminal() || ! $after->isTerminal()) {
            throw new \DomainException('Compare terminal runs for the same tenant, project, actor and question.');
        }
        return ['before'=>$this->metrics($before),'after'=>$this->metrics($after)];
    }
    private function metrics(AgentRun $run): array
    {
        $rows=DB::table('api_tool_measurements')->where('tenant_id',$run->tenant_id)->where('agent_run_id',$run->run_id)->get();
        $complete=$rows->isNotEmpty() && $rows->every(fn ($row) => $row->prompt_tokens !== null && $row->completion_tokens !== null);
        return ['run_id'=>$run->run_id,'status'=>$run->status,'model_calls'=>$rows->count(),'tokens_complete'=>$complete,
            'prompt_tokens'=>$complete ? $rows->sum('prompt_tokens'):null,'completion_tokens'=>$complete ? $rows->sum('completion_tokens'):null,
            'model_latency_ms'=>$rows->sum('latency_ms'),'wall_latency_ms'=>$run->started_at && $run->completed_at ? (int) abs($run->completed_at->diffInMilliseconds($run->started_at)):null,
            'logical_actions'=>(int) data_get($run->counters_json,'logical_calls',0),'http_requests'=>(int) data_get($run->counters_json,'physical_calls',0)];
    }
}

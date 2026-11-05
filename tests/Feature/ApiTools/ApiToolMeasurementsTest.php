<?php

declare(strict_types=1);
namespace Tests\Feature\ApiTools;

use App\Agent\AgentExecutionContext;
use App\Ai\{AgentChatModelScope,AiResponse};
use App\ApiTools\ToolMeasurements;
use App\Models\AgentRun;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ApiToolMeasurementsTest extends TestCase
{
    use RefreshDatabase;
    public function test_opt_in_records_provider_usage_without_prompt_content_and_compares_equivalent_runs(): void
    {
        $tenant=app(TenantContext::class)->current();
        $context=new AgentExecutionContext((string) Str::uuid(),$tenant,'sales','chat','user','1','en','UTC');
        $context->activate();
        $meter=app(ToolMeasurements::class);
        $response=new AiResponse('Private answer','test','test-model',100,20);
        app(AgentChatModelScope::class)->within($context,fn () => $meter->record($response,25));
        $this->assertSame(0,DB::table('api_tool_measurements')->count());
        config()->set('api-tools.measurements_enabled',true);
        app(AgentChatModelScope::class)->within($context,fn () => $meter->record($response,25));
        $row=DB::table('api_tool_measurements')->first();
        $this->assertSame(100,$row->prompt_tokens);
        $this->assertStringNotContainsString('Private answer',json_encode($row));
        $before=new AgentRun(['run_id'=>$context->runId,'tenant_id'=>$tenant,'project_key'=>'sales','actor_id'=>'1','status'=>'completed','input_json'=>['question'=>'Same scenario'],'counters_json'=>['physical_calls'=>3,'logical_calls'=>3],'started_at'=>now()->subSeconds(2),'completed_at'=>now()]);
        $after=new AgentRun([...$before->getAttributes(),'run_id'=>(string) Str::uuid()]);
        $after->input_json=$before->input_json; $after->counters_json=['physical_calls'=>3,'logical_calls'=>1];
        $comparison=$meter->compare($before,$after);
        $this->assertSame(100,$comparison['before']['prompt_tokens']);
        $this->assertNull($comparison['after']['prompt_tokens']);
        $this->assertSame(1,$comparison['after']['logical_actions']);
        $this->assertFalse($comparison['after']['tokens_complete']);
        $after->project_key='other';
        $this->expectException(\DomainException::class); $meter->compare($before,$after);
    }
}

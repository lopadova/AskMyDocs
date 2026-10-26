<?php

declare(strict_types=1);
namespace Tests\Feature\ApiTools;

use App\Agent\AgentExecutionContext;
use App\Ai\{AiManager,AiResponse};
use App\ApiTools\ToolDraftGenerator;
use App\Models\{ProjectMembership,User};
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Padosoft\AskMyDocsConnectorApi\Models\{ApiConnector,ApiRoute};
use Tests\TestCase;

final class ApiToolDraftGeneratorTest extends TestCase
{
    use RefreshDatabase;
    public function test_ai_returns_the_same_editable_definition_format_and_never_publishes_a_draft(): void
    {
        $this->seed(RbacSeeder::class);
        $tenant=app(TenantContext::class)->current();
        $user=User::create(['name'=>'Generator','email'=>'generator@example.test','password'=>bcrypt('test')]);
        $user->assignRole('admin');
        ProjectMembership::create(['tenant_id'=>$tenant,'user_id'=>$user->id,'project_key'=>'sales','role'=>'owner']);
        $context=new AgentExecutionContext((string) Str::uuid(),$tenant,'sales','chat','user',(string) $user->id,'en','UTC'); $context->activate();
        $connector=ApiConnector::create(['tenant_id'=>$tenant,'project_key'=>'sales','name'=>'Orders API','is_active'=>true]);
        $route=ApiRoute::create(['tenant_id'=>$tenant,'api_connector_id'=>$connector->id,'project_key'=>'sales','name'=>'Orders','slug'=>'orders','url'=>'https://api.example.com/orders','http_method'=>'GET','status'=>'active','mode'=>'tool','input_schema'=>['type'=>'object']]);
        $definition=['version'=>1,'input_schema'=>['type'=>'object'],'steps'=>[['id'=>'orders','operation'=>'api','route_id'=>$route->id,'bindings'=>[]]],'output'=>['orders'=>['source'=>'step','step'=>'orders']],'tests'=>[]];
        $this->mock(AiManager::class)->shouldReceive('chat')->once()->withArgs(fn ($system,$prompt,$options) => str_contains($system,'Orders API') && ! str_contains($system,'https://api.example.com') && $prompt === 'Last orders')->andReturn(new AiResponse(json_encode(['name'=>'Last orders','description'=>'Order list','definition'=>$definition]),'test','test',10,20));
        $result=app(ToolDraftGenerator::class)->generate('Last orders',$context);
        $this->assertSame($route->id,$result['definition']['steps'][0]['route_id']);
        $this->assertSame('single',$result['definition']['steps'][0]['collection']['mode']);
        $this->assertSame(10,$result['usage']['prompt_tokens']);
        $this->assertDatabaseCount('api_tools',0);
    }
}

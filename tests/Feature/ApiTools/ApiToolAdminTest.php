<?php

declare(strict_types=1);
namespace Tests\Feature\ApiTools;

use App\ApiTools\Models\{ApiTool,ApiToolVerification};
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Padosoft\AskMyDocsConnectorApi\Models\{ApiConnector,ApiRoute};
use Tests\TestCase;

final class ApiToolAdminTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void
    {
        parent::setUp(); $this->seed(RbacSeeder::class);
        $user=User::create(['name'=>'API tools admin','email'=>'api-admin@example.test','password'=>bcrypt('test')]);
        $user->assignRole('admin'); $this->actingAs($user);
        config()->set('api-tools.enabled',true);
        Bus::fake([\App\ApiTools\Jobs\SweepApiTools::class]);
    }
    public function test_feature_is_off_by_default_and_mutations_are_hidden_when_disabled(): void
    {
        config()->set('api-tools.enabled',false);
        $this->getJson('/api/admin/api-tools')->assertOk()->assertJson(['enabled'=>false,'tools'=>[],'endpoints'=>[]]);
        $this->postJson('/api/admin/api-tools',$this->payload())->assertNotFound();
        $this->assertSame(0,ApiTool::count());
    }
    public function test_administrator_can_save_a_draft_select_scoped_endpoints_and_record_static_verification(): void
    {
        $connector=ApiConnector::create(['tenant_id'=>app(TenantContext::class)->current(),'project_key'=>'sales','name'=>'Sales','is_active'=>true]);
        $route=ApiRoute::create(['tenant_id'=>$connector->tenant_id,'api_connector_id'=>$connector->id,'project_key'=>'sales','name'=>'Orders','slug'=>'orders','url'=>'https://api.example.com/orders','http_method'=>'GET','mode'=>'tool','status'=>'active']);
        $this->getJson('/api/admin/api-tools')->assertOk()->assertJsonCount(0,'endpoints');
        $this->getJson('/api/admin/api-tools?project_key=sales')->assertOk()->assertJsonPath('endpoints.0.id',$route->id);
        $created=$this->postJson('/api/admin/api-tools',$this->payload())->assertOk()->assertJsonPath('tool.failure_threshold',7)->json('tool.id');
        $this->postJson('/api/admin/api-tools/'.$created.'/validate')->assertOk()->assertJson(['valid'=>true]);
        $this->assertSame('passed',ApiToolVerification::where('api_tool_id',$created)->where('mode','static')->firstOrFail()->status);
        $this->postJson('/api/admin/api-tools/'.$created.'/publish')->assertUnprocessable();
        $package=$this->getJson('/api/admin/api-tools/'.$created.'/export')->assertOk()->json();
        $this->postJson('/api/admin/api-tools/import',['package'=>$package,'project_key'=>'sales'])->assertOk()->assertJsonPath('tool.status','draft')->assertJsonPath('tool.failure_threshold',7);
    }
    public function test_simulation_returns_per_step_effective_values_and_separate_public_output(): void
    {
        $payload=$this->payload();
        $payload['definition']['tests']=[['name'=>'Example','mode'=>'simulation','inputs'=>[],'fixtures'=>[],'assertions'=>[['path'=>'report.ok','operator'=>'equals','value'=>true]]]];
        $id=$this->postJson('/api/admin/api-tools',$payload)->assertOk()->json('tool.id');
        $this->postJson('/api/admin/api-tools/'.$id.'/test',['mode'=>'simulation'])->assertOk()
            ->assertJsonPath('steps.0.step_id','report')->assertJsonPath('steps.0.diagnostics.inputs.ok',true)
            ->assertJsonPath('steps.0.diagnostics.origins.ok.source','constant')->assertJsonPath('steps.1.step_id','tool_output');
    }
    public function test_static_validation_does_not_report_success_for_a_disabled_dependency(): void
    {
        $connector=ApiConnector::create(['tenant_id'=>app(TenantContext::class)->current(),'project_key'=>'sales','name'=>'Validation','is_active'=>true]);
        $route=ApiRoute::create(['tenant_id'=>$connector->tenant_id,'api_connector_id'=>$connector->id,'project_key'=>'sales','name'=>'Validate','slug'=>'validate','url'=>'https://api.example.com/validate','http_method'=>'GET','mode'=>'tool','status'=>'active']);
        $payload=$this->payload(); $payload['definition']['steps']=[['id'=>'report','operation'=>'api','route_id'=>$route->id,'bindings'=>[]]];
        $id=$this->postJson('/api/admin/api-tools',$payload)->assertOk()->json('tool.id');
        $connector->update(['is_active'=>false]);
        $this->postJson('/api/admin/api-tools/'.$id.'/validate')->assertOk()->assertJsonPath('valid',false);
        $this->assertSame('failed',ApiToolVerification::where('api_tool_id',$id)->where('mode','static')->firstOrFail()->status);
    }

    public function test_foreign_tenant_records_are_invisible_even_to_administrators(): void
    {
        $id=$this->postJson('/api/admin/api-tools',$this->payload())->assertOk()->json('tool.id');
        ApiTool::whereKey($id)->update(['tenant_id'=>'other']);
        $this->getJson('/api/admin/api-tools/'.$id)->assertNotFound();
        $this->postJson('/api/admin/api-tools/'.$id.'/disable')->assertNotFound();
    }
    public function test_viewer_cannot_manage_tools(): void
    {
        $user=User::create(['name'=>'Viewer','email'=>'viewer-api@example.test','password'=>bcrypt('test')]);
        $user->assignRole('viewer'); $this->actingAs($user);
        $this->getJson('/api/admin/api-tools')->assertForbidden();
        $this->postJson('/api/admin/api-tools',$this->payload())->assertForbidden();
    }
    private function payload(): array
    {
        return ['name'=>'API report','project_key'=>'sales','failure_threshold'=>7,'definition'=>['version'=>1,'input_schema'=>['type'=>'object','properties'=>(object) []],
            'steps'=>[['id'=>'report','operation'=>'compose','bindings'=>['ok'=>['source'=>'constant','value'=>true]]]],'output'=>['report'=>['source'=>'step','step'=>'report']],'tests'=>[]]];
    }
}

<?php

declare(strict_types=1);
namespace Tests\Feature\ApiTools;

use App\Agent\AgentExecutionContext;
use App\ApiTools\{BindingResolver,ContentHasher,DefinitionValidator,ToolGraphCompiler,ToolLifecycle,ToolRuntime};
use App\ApiTools\Models\{ApiToolRun,ApiToolStep};
use App\Models\{ProjectMembership,User};
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Bus,DB,Http,Queue};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Padosoft\AskMyDocsConnectorApi\Models\{ApiConnector,ApiRoute,ApiRouteParameter};
use Padosoft\LaravelFlow\Executor\Jobs\{CoordinatorJob,NodeJob};
use Tests\TestCase;

final class ApiToolRuntimeTest extends TestCase
{
    use RefreshDatabase;
    private AgentExecutionContext $context;
    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(\App\Ai\AiManager::class)->shouldNotReceive('chat','chatWithHistory','chatWithProvider','chatStream');
        $this->seed(RbacSeeder::class);
        $tenant=app(TenantContext::class)->current();
        $user=User::create(['name'=>'Runtime tester','email'=>'runtime@example.test','password'=>bcrypt('secret')]);
        $user->assignRole('admin');
        ProjectMembership::create(['tenant_id'=>$tenant,'user_id'=>$user->id,'project_key'=>'sales','role'=>'owner']);
        $this->context=new AgentExecutionContext((string) Str::uuid(),$tenant,'sales','chat','user',(string) $user->id,'en','UTC');
        $this->context->activate();
        config()->set('api-tools.enabled',true);
        config()->set('laravel-flow.persistence.enabled',false);
        config()->set('connector-api.ssrf.resolve_dns',false);
        config()->set('connector-api.defaults.retry_backoff_ms',0);
        Bus::fake([\App\ApiTools\Jobs\SweepApiTools::class,\App\ApiTools\Jobs\VerifyApiTool::class]);
        Queue::fake();
    }
    public function test_three_calls_transfer_nested_identifiers_and_keep_private_intermediates(): void
    {
        $one=$this->route('order',['type'=>'object','properties'=>['customer'=>['type'=>'object','properties'=>['id'=>['type'=>'integer']]]]]);
        $two=$this->route('customer',['type'=>'object','properties'=>['region'=>['type'=>'integer']]],'id');
        $three=$this->route('region',['type'=>'object','properties'=>['name'=>['type'=>'string']]],'id');
        $definition=$this->definition([
            $this->step('order',$one),$this->step('customer',$two,['id'=>['source'=>'step','step'=>'order','path'=>'customer.id']]),
            $this->step('region',$three,['id'=>['source'=>'step','step'=>'customer','path'=>'region']]),
        ],['public'=>['source'=>'step','step'=>'region','path'=>'name']]);
        Http::fake(['api.example.com/order'=>Http::response(['customer'=>['id'=>42]]),'api.example.com/customer*'=>Http::response(['region'=>9]),'api.example.com/region*'=>Http::response(['name'=>'Europe'])]);
        $run=$this->start($definition);
        foreach (['order','customer','region','tool_output'] as $id) { app(ToolRuntime::class)->executeStep($run->id,$id); }
        app(ToolRuntime::class)->finish($run->id,'completed');
        $run->refresh();
        $this->assertSame(['public'=>'Europe'],$run->outputs);
        $this->assertSame(3,$run->physical_requests);
        Http::assertSent(fn ($r) => str_contains($r->url(),'customer?id=42'));
        $this->assertSame(['customer'=>['id'=>42]],app(ToolRuntime::class)->stepOutputs($run)['order']);
        $raw=DB::table('api_tool_steps')->where('api_tool_run_id',$run->id)->where('step_id','order')->value('output');
        $this->assertStringNotContainsString('customer',$raw);
        $logs=DB::table('api_tool_call_logs')->get();
        $this->assertStringNotContainsString('Europe',json_encode($logs));
        $this->assertStringNotContainsString('customer',json_encode($logs));
        $diagnostic=DB::table('api_tool_steps')->where('api_tool_run_id',$run->id)->where('step_id','region')->value('diagnostics');
        $this->assertStringNotContainsString('Europe',$diagnostic);
        $graph=DB::table('flow_runs')->where('id',$run->flow_run_id)->first();
        $this->assertStringNotContainsString('Europe',json_encode($graph));
        $this->assertFalse(config('laravel-flow.persistence.enabled'));
    }
    public function test_queued_coordinator_claims_both_independent_roots_and_waits_for_mixed_join(): void
    {
        $route=$this->route('root',['type'=>'object']);
        $run=$this->start($this->definition([$this->step('left',$route),$this->step('right',$route),[
            'id'=>'join','operation'=>'compose','bindings'=>['a'=>['source'=>'step','step'=>'left'],'b'=>['source'=>'step','step'=>'right']], 'wait_for'=>[],
        ]],['joined'=>['source'=>'step','step'=>'join']]));
        Queue::assertPushed(CoordinatorJob::class);
        $root=Queue::pushed(CoordinatorJob::class)->first();
        app(\Illuminate\Contracts\Bus\Dispatcher::class)->dispatchNow($root);
        $jobs=Queue::pushed(NodeJob::class)->map(fn ($job) => $job->nodeId)->all();
        $this->assertEqualsCanonicalizing(['left','right'],$jobs);
        $states=DB::table('flow_run_nodes')->where('run_id',$run->flow_run_id)->pluck('status','node_id')->all();
        $this->assertSame('running',$states['left']);
        $this->assertSame('running',$states['right']);
        $this->assertSame('pending',$states['join']);
    }
    public function test_cache_costs_zero_and_retries_each_consume_shared_budget(): void
    {
        $route=$this->route('cache',['type'=>'object']);
        $route->update(['cache_ttl_s'=>60]);
        Http::fakeSequence()->push(['retry'=>true],503)->push(['ok'=>true]);
        $run=$this->start($this->definition([$this->step('a',$route),$this->step('b',$route)],['a'=>['source'=>'step','step'=>'a']]));
        app(ToolRuntime::class)->executeStep($run->id,'a');
        app(ToolRuntime::class)->executeStep($run->id,'b');
        $this->assertSame(2,$run->fresh()->physical_requests);
        Http::assertSentCount(2);
    }
    public function test_budget_exhaustion_and_revoked_authorization_stop_before_another_http_request(): void
    {
        $route=$this->route('budget',['type'=>'object']);
        Http::fakeSequence()->push([],503)->push([]);
        $def=$this->definition([$this->step('a',$route)],['a'=>['source'=>'step','step'=>'a']]);
        $def['limits']['physical_requests']=1;
        $run=$this->start($def);
        try { app(ToolRuntime::class)->executeStep($run->id,'a'); $this->fail('Expected budget stop'); }
        catch (\DomainException $e) { $this->assertSame('api_tool_budget_exhausted',$e->getMessage()); }
        $this->assertSame(1,$run->fresh()->physical_requests);
        Http::assertSentCount(1);
        $run2=$this->start($def);
        ProjectMembership::where('user_id',$this->context->actorId)->delete();
        $this->expectExceptionMessage('api_tool_authorization_revoked');
        app(ToolRuntime::class)->executeStep($run2->id,'a');
    }
    public function test_lists_require_explicit_projection_and_empty_iteration_is_valid(): void
    {
        $resolver=app(BindingResolver::class);
        $this->assertSame([1,2],$resolver->resolve(['source'=>'constant','value'=>[['id'=>1],['id'=>2]],'mode'=>'project','project_path'=>'id'],[],[]));
        $this->assertSame(2,$resolver->resolve(['source'=>'constant','value'=>[['id'=>1],['id'=>2]],'mode'=>'index','index'=>1,'project_path'=>'id'],[],[]));
        $route=$this->route('list',['type'=>'object']);
        $step=$this->step('each',$route);
        $step['iterate']=['source'=>['source'=>'constant','value'=>[]],'max_items'=>10,'allow_partial'=>false];
        $run=$this->start($this->definition([$step],['items'=>['source'=>'step','step'=>'each']]));
        app(ToolRuntime::class)->executeStep($run->id,'each');
        app(ToolRuntime::class)->executeStep($run->id,'tool_output');
        $this->assertSame(['items'=>[]],$run->fresh()->outputs);
        Http::assertNothingSent();
    }
    public function test_mapping_type_mismatch_and_cycles_are_rejected(): void
    {
        $list=$this->route('list',['type'=>'array','items'=>['type'=>'integer']]);
        $detail=$this->route('detail',['type'=>'object'],'id');
        $def=$this->definition([$this->step('a',$list),$this->step('b',$detail,['id'=>['source'=>'step','step'=>'a']])],['a'=>['source'=>'step','step'=>'a']]);
        try { app(DefinitionValidator::class)->validate($def,$this->context->tenantId,'sales'); $this->fail('Type mismatch accepted'); }
        catch (ValidationException $e) { $this->assertStringContainsString('incompatible',$e->getMessage()); }
        $def['steps'][1]['bindings']['id']=['source'=>'constant','value'=>1];
        $def['steps'][0]['wait_for']=['b']; $def['steps'][1]['wait_for']=['a'];
        $this->expectException(ValidationException::class);
        app(DefinitionValidator::class)->validate($def,$this->context->tenantId,'sales');
    }
    public function test_semantic_hash_ignores_key_order_and_credentials_but_changes_for_endpoint_contract(): void
    {
        $hash=app(ContentHasher::class);
        $this->assertSame($hash->hash(['b'=>1,'a'=>['d'=>2,'c'=>3]]),$hash->hash(['a'=>['c'=>3,'d'=>2],'b'=>1]));
        $this->assertNotSame($hash->hash([1,2]),$hash->hash([2,1]));
        $route=$this->route('hash',['type'=>'object']);
        $before=$hash->endpoint($route);
        $route->update(['name'=>'Visual name','last_test_at'=>now()]);
        $this->assertSame($before,$hash->endpoint($route));
        $route->update(['url'=>'https://api.example.com/new']);
        $this->assertNotSame($before,$hash->endpoint($route));
    }
    public function test_cancellation_and_timeout_are_terminal_and_duplicate_completed_step_does_not_reexecute(): void
    {
        $route=$this->route('cancel',['type'=>'object']);
        Http::fake(['*'=>Http::response(['ok'=>true])]);
        $run=$this->start($this->definition([$this->step('a',$route)],['a'=>['source'=>'step','step'=>'a']]));
        app(ToolRuntime::class)->executeStep($run->id,'a'); app(ToolRuntime::class)->executeStep($run->id,'a');
        Http::assertSentCount(1);
        app(ToolRuntime::class)->cancel($run); app(ToolRuntime::class)->finish($run->id,'completed');
        $this->assertSame('cancelled',$run->fresh()->status);
        $run2=$this->start($this->definition([$this->step('a',$route)],['a'=>['source'=>'step','step'=>'a']]));
        $run2->update(['deadline_at'=>now()->subSecond()]);
        try { app(ToolRuntime::class)->executeStep($run2->id,'a'); } catch (\DomainException) {}
        $this->assertSame('timed_out',$run2->fresh()->status);
    }
    public function test_bounded_iteration_partial_errors_keep_a_stable_contract_and_do_not_count_as_failure(): void
    {
        $route=$this->route('partial',['type'=>'object','properties'=>['id'=>['type'=>'integer']]],'id');
        $step=$this->step('each',$route,['id'=>['source'=>'item','path'=>'id']]);
        $step['iterate']=['source'=>['source'=>'constant','value'=>[['id'=>1],['id'=>2]]],'max_items'=>2,'allow_partial'=>true];
        Http::fakeSequence()->push(['id'=>1])->push(['error'=>'missing'],404);
        $run=$this->start($this->definition([$step],['items'=>['source'=>'step','step'=>'each','path'=>'items']]));
        app(ToolRuntime::class)->executeStep($run->id,'each');
        app(ToolRuntime::class)->executeStep($run->id,'tool_output');
        app(ToolRuntime::class)->finish($run->id,'completed');
        $this->assertSame('partial',$run->fresh()->status);
        $this->assertSame(['items'=>[['id'=>1],null]],$run->fresh()->outputs);
        $this->assertSame(0,\App\ApiTools\Models\ApiTool::findOrFail($run->api_tool_id)->consecutive_failures);
    }
    public function test_recovery_reuses_a_committed_step_when_worker_disappears_before_flow_completion(): void
    {
        $route=$this->route('recover',['type'=>'object']);
        Http::fake(['*'=>Http::response(['ok'=>true])]);
        $run=$this->start($this->definition([$this->step('a',$route)],['a'=>['source'=>'step','step'=>'a']]));
        $coordinator=Queue::pushed(CoordinatorJob::class)->first();
        app(\Illuminate\Contracts\Bus\Dispatcher::class)->dispatchNow($coordinator);
        app(ToolRuntime::class)->executeStep($run->id,'a');
        // Domain result committed; worker is gone before NodeExecutor writes its completion.
        $this->assertSame('running',DB::table('flow_run_nodes')->where('run_id',$run->flow_run_id)->where('node_id','a')->value('status'));
        app(ToolRuntime::class)->recover($run);
        $this->assertSame('succeeded',DB::table('flow_run_nodes')->where('run_id',$run->flow_run_id)->where('node_id','a')->value('status'));
        app(\Illuminate\Contracts\Bus\Dispatcher::class)->dispatchNow($coordinator);
        $join=Queue::pushed(NodeJob::class)->first(fn ($job) => $job->nodeId === 'tool_output');
        app(\Illuminate\Contracts\Bus\Dispatcher::class)->dispatchNow($join);
        app(\Illuminate\Contracts\Bus\Dispatcher::class)->dispatchNow($coordinator);
        $this->assertSame('completed',$run->fresh()->status);
        $this->assertSame(1,$run->fresh()->physical_requests);
        Http::assertSentCount(1);
    }
    public function test_fingerprint_tracks_business_headers_and_public_auth_but_excludes_rotating_secrets(): void
    {
        $route=$this->route('fingerprint',['type'=>'object','description'=>'Visual description']);
        $route->connector->update(['headers'=>['X-API-Version'=>'v1']]);
        $profile=\Padosoft\AskMyDocsConnectorApi\Models\ApiAuthProfile::create(['tenant_id'=>$route->tenant_id,'api_connector_id'=>$route->api_connector_id,'type'=>'basic','credentials'=>['username'=>'account-a','password'=>'private-one']]);
        $route->update(['auth_profile_id'=>$profile->id]);
        $hasher=app(ContentHasher::class); $first=$hasher->endpoint($route);
        $profile->update(['credentials'=>['username'=>'account-a','password'=>'private-two']]);
        $this->assertSame($first,$hasher->endpoint($route));
        $route->update(['output_schema'=>['type'=>'object','description'=>'Different visual description']]);
        $this->assertSame($first,$hasher->endpoint($route));
        $route->connector->update(['headers'=>['X-API-Version'=>'v2']]);
        $this->assertNotSame($first,$hasher->endpoint($route));
        $versionTwo=$hasher->endpoint($route);
        $profile->update(['credentials'=>['username'=>'account-b','password'=>'private-two']]);
        $this->assertNotSame($versionTwo,$hasher->endpoint($route));
    }

    public function test_catalog_has_a_valid_empty_object_signature_and_bounded_request_estimates(): void
    {
        $route=$this->route('catalog',['type'=>'object']);
        $steps=[];
        for ($i=1;$i<=40;$i++) { $steps[]=$this->step('call_'.$i,$route); }
        $definition=$this->definition($steps,['first'=>['source'=>'step','step'=>'call_1']]);
        $definition['limits']['physical_requests']=30;
        $run=$this->start($definition);
        $catalog=app(\App\ApiTools\ToolCatalog::class)->forContext($this->context);
        $tool=$catalog['api_tool_'.$run->api_tool_id];
        $this->assertSame(30,$tool->physicalLikely);
        $wire=json_decode(json_encode($tool->openAiFunction(),JSON_THROW_ON_ERROR));
        $this->assertInstanceOf(\stdClass::class,$wire->function->parameters->properties);
        $this->assertStringNotContainsString('route_id',json_encode($wire));
    }

    public function test_live_public_read_only_chain_and_baseline_have_equivalent_outputs(): void
    {
        if (getenv('API_TOOLS_LIVE_SMOKE') !== '1') { $this->markTestSkipped('Opt in to real public HTTPS requests with API_TOOLS_LIVE_SMOKE=1.'); }
        $user=$this->route('user',['type'=>'object','properties'=>['id'=>['type'=>'integer']]]);
        $user->update(['url'=>'https://jsonplaceholder.typicode.com/users/1']);
        $posts=$this->route('posts',['type'=>'array','items'=>['type'=>'object','properties'=>['id'=>['type'=>'integer']]]],'userId');
        $posts->update(['url'=>'https://jsonplaceholder.typicode.com/posts']);
        $comments=$this->route('comments',['type'=>'array','items'=>['type'=>'object']],'postId');
        $comments->update(['url'=>'https://jsonplaceholder.typicode.com/comments']);
        $definition=$this->definition([$this->step('user',$user),$this->step('posts',$posts,['userId'=>['source'=>'step','step'=>'user','path'=>'id']]),$this->step('comments',$comments,['postId'=>['source'=>'step','step'=>'posts','mode'=>'index','index'=>0,'project_path'=>'id']])],['comments'=>['source'=>'step','step'=>'comments']]);
        $assertions=[['path'=>'comments','operator'=>'min_count','value'=>1]];
        $definition['tests']=[['name'=>'Mapping simulation','mode'=>'simulation','inputs'=>[], 'fixtures'=>['user'=>['id'=>1],'posts'=>[['id'=>1]],'comments'=>[['id'=>1]]],'assertions'=>$assertions],['name'=>'Public live chain','mode'=>'live','inputs'=>[],'assertions'=>$assertions]];
        $tool=app(ToolLifecycle::class)->saveDraft(null,['name'=>'Live public fixture','definition'=>$definition],$this->context);
        $baselineStart=microtime(true);
        $baseline=app(\App\ApiTools\ToolHttpBudget::class)->measure(function () use ($user,$posts,$comments) {
            $collector=app(\App\Agent\Tools\ApiToolCollector::class);
            $one=$collector->executeRequest($user,[],$this->context);
            $two=$collector->executeRequest($posts,['userId'=>$one['id']],$this->context);
            return $collector->executeRequest($comments,['postId'=>$two[0]['id']],$this->context);
        });
        $baselineMs=(int) round((microtime(true)-$baselineStart)*1000);
        $start=microtime(true);
        $verifications=app(\App\ApiTools\ToolVerificationService::class)->start($tool,$this->context);
        $handled=[];
        for ($i=0;$i<20;$i++) {
            $next=Queue::pushed(CoordinatorJob::class)->merge(Queue::pushed(NodeJob::class))->first(fn ($job) => ! isset($handled[spl_object_id($job)]));
            if (! $next) { break; }
            $handled[spl_object_id($next)]=true;
            app(\Illuminate\Contracts\Bus\Dispatcher::class)->dispatchNow($next);
        }
        $run=ApiToolRun::where('api_tool_id',$tool->id)->firstOrFail();
        $this->assertSame('completed',$run->status,$run->error_code ?? '');
        $this->assertSame($baseline['body'],$run->outputs['comments']);
        $this->assertSame(3,$run->physical_requests);
        $this->assertSame(3,$baseline['physical_requests']);
        foreach ($verifications as $v) { $this->assertSame('passed',$v->fresh()->status); }
        $this->assertTrue(app(ToolLifecycle::class)->publish($tool->fresh(),$this->context)->available());
        file_put_contents('/tmp/api-tools-live-metrics.json',json_encode(['scenario'=>'public user/posts/comments read-only chain','baseline_http'=>$baseline['physical_requests'],'composed_http'=>$run->physical_requests,'baseline_ms'=>$baselineMs,'composed_ms'=>(int) round((microtime(true)-$start)*1000),'runtime_llm_calls'=>0,'agent_llm_tokens'=>null,'delivery'=>'real HTTPS; queued jobs drained in test process'],JSON_PRETTY_PRINT));
    }

    private function route(string $slug,array $output,?string $parameter=null): ApiRoute
    {
        $connector=ApiConnector::create(['tenant_id'=>$this->context->tenantId,'project_key'=>'sales','name'=>$slug,'is_active'=>true]);
        $route=ApiRoute::create(['tenant_id'=>$this->context->tenantId,'project_key'=>'sales','api_connector_id'=>$connector->id,'name'=>$slug,'slug'=>$slug,'url'=>'https://api.example.com/'.$slug,'http_method'=>'GET','mode'=>'tool','status'=>'active','input_schema'=>['type'=>'object','properties'=>$parameter ? [$parameter=>['type'=>'integer']] : (object) [],'required'=>$parameter ? [$parameter] : []],'output_schema'=>$output]);
        if ($parameter) { ApiRouteParameter::create(['tenant_id'=>$this->context->tenantId,'api_route_id'=>$route->id,'name'=>$parameter,'source'=>'llm','location'=>'query','type'=>'integer','required'=>true]); }
        return $route;
    }
    private function step(string $id,ApiRoute $route,array $bindings=[]): array { return ['id'=>$id,'operation'=>'api','route_id'=>$route->id,'bindings'=>$bindings,'wait_for'=>[],'collection'=>['mode'=>'single']]; }
    private function definition(array $steps,array $output): array { return ['version'=>1,'input_schema'=>['type'=>'object','properties'=>(object) []],'steps'=>$steps,'output'=>$output,'tests'=>[],'limits'=>['physical_requests'=>20,'timeout_seconds'=>60]]; }
    private function start(array $definition): ApiToolRun
    {
        $tool=app(ToolLifecycle::class)->saveDraft(null,['name'=>'Tool '.Str::uuid(),'definition'=>$definition],$this->context);
        $tool->update(['status'=>'active','publication_intent'=>true]);
        return app(ToolRuntime::class)->start($tool,[],$this->context);
    }
}

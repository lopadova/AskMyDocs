<?php

declare(strict_types=1);
namespace App\Http\Controllers\Api\Admin;

use App\Agent\AgentExecutionContext;
use App\ApiTools\{ToolAccess,ToolLifecycle,DefinitionValidator,ToolVerificationService,ToolPackageService,ToolDraftGenerator,ToolRuntime,ConnectorSecretGuard};
use App\ApiTools\Models\{ApiTool,ApiToolRun,ApiToolStep,ApiToolRevision,ApiToolVerification};
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class ApiToolController
{
    public function index(Request $request): array
    {
        if (! config('api-tools.enabled',false)) { return ['enabled'=>false,'tools'=>[],'endpoints'=>[]]; }
        $ctx = $this->context($request, $request->input('project_key') ?: ($request->user()->canReadAllProjects() ? '' : ($request->user()->allowedProjects()[0] ?? '')));
        $query = ApiTool::query()->forTenant($ctx->tenantId)->with('revision');
        if (! $request->user()->canReadAllProjects()) { $query->whereIn('project_key',$request->user()->allowedProjects()); }
        return ['enabled'=>(bool) config('api-tools.enabled'),'tools'=>$query->orderBy('name')->get()->map(fn ($t) => $this->tool($t))->all(),'endpoints'=>$this->endpoints($ctx)];
    }
    public function show(Request $request,int $id): array
    {
        $tool = $this->find($request,$id);
        $ctx = $this->context($request,$tool->project_key);
        app(ToolLifecycle::class)->checkDependencies($tool,$ctx);
        $runs = ApiToolRun::query()->forTenant($ctx->tenantId)->where('api_tool_id',$id)->latest()->limit(25)->get()->map(function ($run) use ($ctx) {
            $row = $run->toArray();
            $row['steps'] = ApiToolStep::query()->forTenant($ctx->tenantId)->where('api_tool_run_id',$run->id)->get()->toArray();
            return $row;
        });
        return ['tool'=>$this->tool($tool),'endpoints'=>$this->endpoints($ctx),'revisions'=>ApiToolRevision::query()->forTenant($ctx->tenantId)->where('api_tool_id',$id)->orderByDesc('number')->get(['id','number','definition_hash','created_at']),
            'runs'=>$runs,'verifications'=>ApiToolVerification::query()->forTenant($ctx->tenantId)->where('api_tool_id',$id)->latest('id')->limit(25)->get()];
    }
    public function store(Request $request): array { return $this->save($request,null); }
    public function update(Request $request,int $id): array { return $this->save($request,$this->find($request,$id)); }
    private function save(Request $request,?ApiTool $tool): array
    {
        $data = $request->validate(['name'=>'required|string|max:255','description'=>'nullable|string|max:10000','definition'=>'required|array','project_key'=>'nullable|string|max:255','failure_threshold'=>'sometimes|integer|min:1|max:100']);
        $context = $this->context($request,$tool?->project_key ?? $data['project_key'] ?? '');
        return ['tool'=>$this->tool(app(ToolLifecycle::class)->saveDraft($tool,$data,$context))];
    }
    public function validateDefinition(Request $request,int $id): array
    {
        $tool = $this->find($request,$id);
        $records = app(ToolVerificationService::class)->start($tool,$this->context($request,$tool->project_key),'static');
        $valid=collect($records)->every(fn ($v) => $v->status === 'passed');
        return ['valid'=>$valid,'errors'=>$valid ? []:['Current endpoint or binding validation failed.'],'verifications'=>$records];
    }
    public function test(Request $request,int $id): array
    {
        $tool = $this->find($request,$id);
        $data = $request->validate(['mode'=>'sometimes|in:static,simulation,live,full']);
        $records=app(ToolVerificationService::class)->start($tool,$this->context($request,$tool->project_key),$data['mode'] ?? 'full');
        $simulation=collect($records)->first(fn ($v) => $v->mode === 'simulation');
        $steps=app(ConnectorSecretGuard::class)->mask($simulation?->results['cases'][0]['steps'] ?? []);
        return ['verifications'=>$records,'steps'=>array_values($steps)];
    }
    public function publish(Request $r,int $id): array { $t=$this->find($r,$id); return ['tool'=>$this->tool(app(ToolLifecycle::class)->publish($t,$this->context($r,$t->project_key)))]; }
    public function disable(Request $r,int $id): array { $t=$this->find($r,$id); return ['tool'=>$this->tool(app(ToolLifecycle::class)->disable($t,$this->context($r,$t->project_key)))]; }
    public function enable(Request $r,int $id): array { $t=$this->find($r,$id); return ['tool'=>$this->tool(app(ToolLifecycle::class)->enable($t,$this->context($r,$t->project_key)))]; }
    public function export(Request $r,int $id): array { return app(ToolPackageService::class)->export($this->find($r,$id)); }
    public function import(Request $r): array
    {
        $data=$r->validate(['package'=>'required|array','project_key'=>'nullable|string|max:255']);
        $ctx=$this->context($r,$data['project_key'] ?? '');
        return ['tool'=>$this->tool(app(ToolPackageService::class)->import($data['package'],$ctx->projectKey ?? '',$ctx))];
    }
    public function generate(Request $r): array
    {
        $data=$r->validate(['prompt'=>'required|string|max:10000','project_key'=>'nullable|string|max:255']);
        return app(ToolDraftGenerator::class)->generate($data['prompt'],$this->context($r,$data['project_key'] ?? ''));
    }
    public function cancel(Request $r,string $runId): array
    {
        $run=ApiToolRun::query()->forTenant(app(TenantContext::class)->current())->findOrFail($runId);
        $this->find($r,$run->api_tool_id);
        app(ToolRuntime::class)->cancel($run);
        return ['run'=>$run->fresh()];
    }
    private function find(Request $r,int $id): ApiTool
    {
        $tool=ApiTool::query()->forTenant(app(TenantContext::class)->current())->with('revision')->findOrFail($id);
        $this->context($r,$tool->project_key);
        return $tool;
    }
    private function context(Request $r,?string $project=null): AgentExecutionContext
    {
        $ctx=new AgentExecutionContext((string) Str::uuid(),app(TenantContext::class)->current(),($project ?? $r->input('project_key')) ?: null,'chat','user',(string) $r->user()->id,\App\Support\SupportedLocale::normalize($r->user()->locale ?? null),(string) config('app.timezone','UTC'));
        try { app(ToolAccess::class)->assertActor($ctx); } catch (\DomainException) { abort(403,'API tool scope is not authorized.'); }
        return $ctx;
    }
    private function tool(ApiTool $tool): array { return [...$tool->toArray(),'definition'=>$tool->revision->definition,'revision'=>null]; }
    private function endpoints(AgentExecutionContext $ctx): array
    {
        return app(ToolAccess::class)->routes($ctx->tenantId,$ctx->projectKey ?? '')->map(fn ($r) => ['id'=>$r->id,'name'=>$r->name,'connector_id'=>$r->api_connector_id,'connector_name'=>$r->connector?->name,'method'=>$r->http_method->value,'input_schema'=>app(DefinitionValidator::class)->publicInputSchema($r), 'output_schema'=>$r->output_schema ?? [],'parameters'=>$r->parameters->map(fn ($p) => $p->only(['name','type','required','source','location'])),'example'=>app(ConnectorSecretGuard::class)->mask($r->last_test_payload)])->all();
    }
}

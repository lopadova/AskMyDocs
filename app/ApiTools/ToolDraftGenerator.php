<?php

declare(strict_types=1);
namespace App\ApiTools;

use App\Agent\AgentExecutionContext;
use App\Ai\AiManager;
use Illuminate\Validation\ValidationException;

/** The model authors only editable drafts; no model is called by ToolRuntime. */
final class ToolDraftGenerator
{
    public function generate(string $prompt, AgentExecutionContext $context): array
    {
        app(ToolAccess::class)->assertActor($context);
        $routes = app(ToolAccess::class)->routes($context->tenantId,$context->projectKey ?? '');
        $endpoints = $routes->map(fn ($r) => ['id'=>$r->id,'name'=>$r->name,'connector'=>$r->connector?->name,'input_schema'=>app(DefinitionValidator::class)->publicInputSchema($r),'output_schema'=>$r->output_schema])->all();
        app(ConnectorSecretGuard::class)->assertClean($endpoints,$routes);
        $instructions = <<<'TEXT'
Return JSON only: {name,description,definition}. Create a read-only API tool draft from the user's intent using only the listed endpoint IDs. Never invent endpoints, schemas or secrets. Definition format: {version:1,input_schema:{type:"object",properties:{},required:[]},steps:[{id:"stable_key",name:"Call",operation:"api",route_id:1,bindings:{endpoint_parameter:{source:"input",path:"input_name"}},wait_for:[],collection:{mode:"single"}}],output:{result:{source:"step",step:"stable_key",path:""}},tests:[],limits:{physical_requests:30,timeout_seconds:120}}. Bindings can use source input/constant/step/item; constant has value; step has step ID and path; mode value/index/project; index needs explicit index, project has project_path. Dependencies follow mappings automatically. Independent calls execute in parallel. List to scalar needs explicit index. iterate:{source:Binding,max_items:10,allow_partial:false} explicitly iterates. Operations select/filter/sort/limit/compose/join are deterministic. Never silently collect all pages. Output is independent of intermediates. Include simulation cases with fixtures and assertions and live cases using user-supplied safe example inputs. Do not fabricate live test inputs when unknown; leave tests empty for administrator. Assertions {path,operator:exists|type|equals|count|min_count|max_count,value}. This is a draft, not publication.
TEXT;
        $response = app(AiManager::class)->chat($instructions."\nEndpoints: ".json_encode($endpoints,JSON_THROW_ON_ERROR),$prompt,['temperature'=>0,'response_format'=>['type'=>'json_object']]);
        try { $data = json_decode($response->content,true,64,JSON_THROW_ON_ERROR); }
        catch (\Throwable) { throw ValidationException::withMessages(['prompt'=>'The provider did not return a valid tool draft.']); }
        $definition = app(DefinitionValidator::class)->validate($data['definition'] ?? [],$context->tenantId,$context->projectKey ?? '');
        return ['name'=>(string) ($data['name'] ?? 'New API tool'),'description'=>(string) ($data['description'] ?? ''),'definition'=>$definition,'usage'=>['prompt_tokens'=>$response->promptTokens,'completion_tokens'=>$response->completionTokens]];
    }
}

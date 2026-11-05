<?php

declare(strict_types=1);
namespace App\ApiTools;

use App\Agent\AgentExecutionContext;
use App\Agent\Tools\AgentToolDefinition;
use App\ApiTools\Models\ApiTool;

final class ToolCatalog
{
    /** Same authoritative catalog for the agent and direct chat. Graphs never leave the server. */
    public function forContext(AgentExecutionContext $context): array
    {
        if (! config('api-tools.enabled', false) || $context->channel !== 'chat') { return []; }
        try { app(ToolAccess::class)->assertActor($context); } catch (\DomainException) { return []; }
        $tools = [];
        foreach (ApiTool::query()->forTenant($context->tenantId)->whereIn('project_key',['',$context->projectKey ?? ''])->where('publication_intent',true)->where('manual_disabled',false)->with('revision')->get() as $tool) {
            if (! app(ToolLifecycle::class)->checkDependencies($tool,$context) || ! $tool->available()) { continue; }
            $definition = $tool->revision->definition;
            $name = 'api_tool_'.$tool->id;
            $tools[$name] = new AgentToolDefinition(
                name:$name, displayName:$tool->name, description:$tool->description.' Reusable verified API tool. Prefer this tool when its stated purpose covers the request.',kind:'api',
                inputSchema:SchemaWire::normalize($definition['input_schema']),readOnly:true,idempotent:true,physicalMinimum:0,physicalLikely:min($definition['limits']['physical_requests'],count(array_filter($definition['steps'],fn ($s) => $s['operation'] === 'api'))),
                physicalMaximum:$definition['limits']['physical_requests'],executorReference:'api_tool:'.$tool->id,
                metadata:['source_runtime'=>'api_tool','source_key'=>'api_tool:'.$tool->id,'source_name'=>$tool->name,'source_project_key'=>$tool->project_key,'content_hash'=>$tool->content_hash,'intent_tags'=>preg_split('/\s+/u',mb_strtolower($tool->name.' '.$tool->description)),'endpoint_type'=>'detail'],
            );
        }
        return $tools;
    }
}

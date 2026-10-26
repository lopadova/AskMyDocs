<?php

declare(strict_types=1);
namespace App\ApiTools;

use Padosoft\LaravelFlow\Graph\GraphDefinition;
use Padosoft\LaravelFlow\Graph\GraphNode;
use Padosoft\LaravelFlow\Graph\Connection;

final class ToolGraphCompiler
{
    public function __construct(private DefinitionValidator $validator) {}
    public function compile(array $definition, string $runId): GraphDefinition
    {
        $nodes = [];
        $wires = [];
        foreach ($definition['steps'] as $step) {
            $nodes[] = new GraphNode($step['id'], 'askmydocs.api_tool.operation', ['tool_run_id'=>$runId,'step_id'=>$step['id']]);
            foreach ($this->validator->dependencies($step) as $dep) { $wires[] = new Connection($dep,'ref',$step['id'],'dependencies'); }
            $wires[] = new Connection($step['id'],'ref','tool_output','dependencies');
        }
        $nodes[] = new GraphNode('tool_output', 'askmydocs.api_tool.operation', ['tool_run_id'=>$runId,'step_id'=>'tool_output']);
        return new GraphDefinition($nodes, $wires, ['api_tool_run_id'=>$runId]);
    }
}

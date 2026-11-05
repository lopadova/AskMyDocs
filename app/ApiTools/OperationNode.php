<?php

declare(strict_types=1);
namespace App\ApiTools;

use Padosoft\LaravelFlow\Node\Attributes\{FlowNode,Input,Output};
use Padosoft\LaravelFlow\Node\{FlowNodeHandler,NodeContext,NodeResult,PortType};

#[FlowNode(type: 'askmydocs.api_tool.operation', category: 'api_tools', name: 'API tool operation')]
final class OperationNode implements FlowNodeHandler
{
    #[Input(type: PortType::Text, required: true)]
    public string $tool_run_id;
    #[Input(type: PortType::Text, required: true)]
    public string $step_id;
    #[Input(type: PortType::Json, multiple: true)]
    public array $dependencies = [];
    #[Output(type: PortType::Json)]
    public array $ref;
    public function execute(NodeContext $context): NodeResult
    {
        if ($context->dryRun) { return NodeResult::dryRunSkipped(); }
        $runId = (string) $context->inputs['tool_run_id'];
        $stepId = (string) $context->inputs['step_id'];
        try {
            app(ToolRuntime::class)->executeStep($runId, $stepId, $context->flowRunId);
            return NodeResult::success(['ref'=>['run'=>$runId,'step'=>$stepId]]);
        } catch (\Throwable $e) {
            // Only stable codes leave the encrypted host runtime for generic Flow persistence.
            return NodeResult::failed(new \RuntimeException(str_starts_with($e->getMessage(),'api_tool_') ? $e->getMessage() : 'api_tool_step_failed'));
        }
    }
}

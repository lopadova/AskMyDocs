<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Agent\AgentRunAccess;
use App\Agent\AgentRunControl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class AgentRunControlController extends Controller
{
    public function cancel(Request $request, string $run, AgentRunAccess $access, AgentRunControl $control): JsonResponse
    {
        $agentRun = $access->forUserOrFail($run, $request->user());

        try {
            $agentRun = $control->cancel($agentRun);
        } catch (\DomainException $exception) {
            return response()->json(['error' => $exception->getMessage()], 409);
        }

        return response()->json($this->serialize($agentRun));
    }

    public function resume(Request $request, string $run, AgentRunAccess $access, AgentRunControl $control): JsonResponse
    {
        $data = $request->validate([
            'logical_extension' => ['sometimes', 'integer', 'min:0'],
            'physical_extension' => ['required', 'integer', 'min:1'],
        ]);
        $agentRun = $access->forUserOrFail($run, $request->user());

        try {
            $agentRun = $control->resume(
                $agentRun,
                (int) ($data['logical_extension'] ?? 0),
                (int) $data['physical_extension'],
            );
        } catch (\DomainException $exception) {
            return response()->json(['error' => $exception->getMessage()], 409);
        }

        return response()->json($this->serialize($agentRun), 202);
    }

    /** @return array<string,mixed> */
    private function serialize(\App\Models\AgentRun $run): array
    {
        return [
            'run_id' => $run->run_id,
            'status' => $run->status,
            'locale' => $run->locale,
            'budget' => $run->budget_json ?? [],
        ];
    }
}

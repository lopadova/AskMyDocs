<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Agent\AgentEventPublisher;
use App\Agent\AgentRunAccess;
use App\Agent\AgentTerminalExecutionFinalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

/** Explicit operator repair of one owned terminal run; no jobs or requests replay. */
final class ChatApiReconcileRunController extends Controller
{
    public function __invoke(Request $request, string $run, AgentRunAccess $access,
        AgentTerminalExecutionFinalizer $finalizer, AgentEventPublisher $events): JsonResponse
    {
        $record = $access->forUserOrFail($run, $request->user());
        return DB::transaction(function () use ($record, $finalizer, $events): JsonResponse {
            $locked = $record->newQuery()->forTenant($record->tenant_id)->whereKey($record->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->isTerminal(), 409, 'Only terminal runs can be reconciled.');
            $outcomes = $finalizer->close($locked);
            foreach ($outcomes as $outcome) {
                $events->publish($locked, 'tool.failed', 'tool.failed', ['tool' => $outcome['tool']],
                    [...$outcome, 'outcome_unknown' => true], canCancel: false);
            }
            return response()->json(['schema_version' => 1, 'run_id' => $locked->run_id,
                'status' => $locked->status, 'closed_tools' => count($outcomes)]);
        });
    }
}

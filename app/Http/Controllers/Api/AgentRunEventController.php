<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Agent\AgentEventStream;
use App\Agent\AgentRunAccess;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AgentRunEventController extends Controller
{
    public function __invoke(
        Request $request,
        string $run,
        AgentEventStream $stream,
        AgentRunAccess $access,
    ): StreamedResponse {
        $user = $request->user();
        abort_if($user === null, 401);

        $agentRun = $access->forUserOrFail($run, $user);

        $after = max(
            0,
            (int) ($request->query('after') ?? $request->header('Last-Event-ID', 0)),
        );

        return response()->stream(function () use ($stream, $agentRun, $after): void {
            foreach ($stream->frames($agentRun, $after) as $frame) {
                echo $frame;
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}

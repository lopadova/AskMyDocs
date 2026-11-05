<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Agent\AgentEventPublisher;
use App\Agent\AgentRunAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** Read durable progress without tying up a connection or executing the job. */
final class ChatApiRunController extends Controller
{
    public function __invoke(Request $request, string $run, AgentRunAccess $access, AgentEventPublisher $publisher): JsonResponse
    {
        $validated = $request->validate(['after' => ['sometimes', 'integer', 'min:0']]);
        $record = $access->forUserOrFail($run, $request->user());
        abort_unless($request->user()->canReadAllProjects() || $record->project_key === null
            || in_array($record->project_key, $request->user()->allowedProjects(), true), 403);
        $events = $record->events()->where('sequence', '>', $validated['after'] ?? 0)
            ->orderBy('sequence')->limit(100)->get();
        $events->each(fn ($event) => $event->setRelation('run', $record));
        $cursor = (int) ($events->last()?->sequence ?? ($validated['after'] ?? 0));
        $diagnostics = null;
        if ($request->user()->tokenCan('chat:debug') && $request->user()->hasAnyRole(['admin', 'super-admin'])) {
            $diagnostics = app(\App\Agent\Debug\RunDiagnosticSnapshot::class)
                ->build($record->load(['events', 'toolExecutions']), now(), collect([$record]));
        }

        return response()->json([
            'schema_version' => 1, 'run_id' => $record->run_id, 'conversation_id' => $record->conversation_id,
            'status' => $record->status, 'terminal' => $record->isTerminal(), 'error_code' => $record->error_code,
            'started_at' => $record->started_at, 'completed_at' => $record->completed_at,
            'counters' => $record->counters_json ?? [],
            'diagnostics' => $diagnostics,
            ...$diagnostics !== null && is_string(data_get($record->input_json, 'qa.campaign_id'))
                ? ['qa_campaign' => app(\App\Services\Qa\CampaignLedger::class)->summary(
                    app(\App\Services\Qa\CampaignLedger::class)->owned(data_get($record->input_json, 'qa.campaign_id'),
                        $record->tenant_id, (string) $record->project_key, $request->user()->id))] : [],
            'events' => $events->map(fn ($event) => $publisher->serialize($event))->all(),
            'next_after' => $cursor, 'has_more' => $record->events()->where('sequence', '>', $cursor)->exists(),
            'tools' => $record->toolExecutions()->orderBy('logical_index')->get([
                'id', 'logical_index', 'tool_name', 'tool_kind', 'status', 'error_code',
                'physical_request_count', 'latency_ms', 'started_at', 'completed_at',
            ])->toArray(),
        ])->header('Cache-Control', 'private, no-store');
    }
}

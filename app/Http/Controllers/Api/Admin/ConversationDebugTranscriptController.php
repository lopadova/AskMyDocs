<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Models\AdminCommandAudit;
use App\Models\AgentPlannerShadowReport;
use App\Models\AgentRun;
use App\Models\AgentRunEvent;
use App\Models\AgentToolExecution;
use App\Models\Conversation;
use App\Models\Message;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Builds the durable, tenant-scoped debugging record for one chat.
 *
 * This deliberately exposes raw persisted run payloads: they contain the
 * retrieval query, returned evidence, planner decisions and tool results that
 * a super-admin needs to investigate an answer. The route middleware is the
 * authorization boundary; never reuse this controller on a reader-facing API.
 */
final class ConversationDebugTranscriptController extends Controller
{
    public function __invoke(
        Request $request,
        Conversation $conversation,
        TenantContext $tenants,
    ): JsonResponse {
        $tenantId = $tenants->current();

        // Resolve once more under the active tenant rather than relying only on
        // route binding. This keeps the exported graph tenant-bound even if a
        // future route changes its binding behavior.
        $conversation = Conversation::query()
            ->forTenant($tenantId)
            ->findOrFail($conversation->getKey());

        $messages = Message::query()
            ->forTenant($tenantId)
            ->where('conversation_id', $conversation->getKey())
            ->orderBy('id')
            ->get();

        $runs = AgentRun::query()
            ->forTenant($tenantId)
            ->where('conversation_id', $conversation->getKey())
            ->with([
                'events' => static fn ($query) => $query->orderBy('sequence')->orderBy('id'),
                'toolExecutions' => static fn ($query) => $query->orderBy('logical_index')->orderBy('id'),
                'plannerShadowReports' => static fn ($query) => $query->orderBy('iteration')->orderBy('id'),
            ])
            ->orderBy('id')
            ->get();

        $exportedAt = now();
        $payload = [
            'schema_version' => 1,
            'kind' => 'askmydocs.conversation_debug_transcript',
            'exported_at' => $exportedAt->toIso8601String(),
            'tenant_id' => $tenantId,
            'conversation' => $this->conversation($conversation),
            'messages' => $messages
                ->map(fn (Message $message): array => $this->message($message))
                ->values()
                ->all(),
            'agent_runs' => $runs
                ->map(fn (AgentRun $run): array => $this->run($run))
                ->values()
                ->all(),
            'coverage' => [
                'messages' => 'All persisted messages in the conversation, in chronological order.',
                'agent_runs' => 'All persisted planner, retrieval, evidence, event and tool-execution data linked to this conversation.',
                'note' => 'Provider traffic that was never persisted by the application cannot be reconstructed.',
            ],
        ];

        // A transcript can contain the exact prompts and retrieved text. Keep a
        // durable forensic record of who exported it, without duplicating the
        // transcript itself in the audit table.
        AdminCommandAudit::query()->create([
            'tenant_id' => $tenantId,
            'user_id' => $request->user()?->id,
            'command' => 'chat:debug-transcript-download',
            'args_json' => [
                'conversation_id' => $conversation->getKey(),
                'messages' => $messages->count(),
                'agent_runs' => $runs->count(),
            ],
            'status' => AdminCommandAudit::STATUS_COMPLETED,
            'stdout_head' => 'Conversation debug transcript exported.',
            'started_at' => $exportedAt,
            'completed_at' => now(),
            'client_ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
        ]);

        $filename = sprintf(
            'chat-debug-%d-%s.json',
            $conversation->getKey(),
            $exportedAt->format('Ymd-His'),
        );

        return response()
            ->json($payload, 200, [
                'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
                'Cache-Control' => 'private, no-store',
            ])
            ->setEncodingOptions(
                JSON_PRETTY_PRINT
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_INVALID_UTF8_SUBSTITUTE,
            );
    }

    /** @return array<string, mixed> */
    private function conversation(Conversation $conversation): array
    {
        return [
            'id' => $conversation->getKey(),
            'tenant_id' => $conversation->tenant_id,
            'user_id' => $conversation->user_id,
            'title' => $conversation->title,
            'project_key' => $conversation->project_key,
            'chat_folder_id' => $conversation->chat_folder_id,
            'pinned_at' => $conversation->pinned_at?->toIso8601String(),
            'archived_at' => $conversation->archived_at?->toIso8601String(),
            'importance' => $conversation->importance?->value,
            'session_recap' => $conversation->session_recap,
            'created_at' => $conversation->created_at?->toIso8601String(),
            'updated_at' => $conversation->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function message(Message $message): array
    {
        return [
            'id' => $message->getKey(),
            'tenant_id' => $message->tenant_id,
            'conversation_id' => $message->conversation_id,
            'agent_run_id' => $message->agent_run_id,
            'role' => $message->role,
            'content' => $message->content,
            'metadata' => $message->metadata,
            'rating' => $message->rating,
            'confidence' => $message->confidence,
            'refusal_reason' => $message->refusal_reason,
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function run(AgentRun $run): array
    {
        return [
            'id' => $run->getKey(),
            'run_id' => $run->run_id,
            'tenant_id' => $run->tenant_id,
            'project_key' => $run->project_key,
            'user_id' => $run->user_id,
            'conversation_id' => $run->conversation_id,
            'widget_identity_id' => $run->widget_identity_id,
            'widget_session_id' => $run->widget_session_id,
            'channel' => $run->channel,
            'actor_type' => $run->actor_type,
            'actor_id' => $run->actor_id,
            'locale' => $run->locale,
            'timezone' => $run->timezone,
            'status' => $run->status,
            'input_json' => $run->input_json,
            'plan_json' => $run->plan_json,
            'budget_json' => $run->budget_json,
            'counters_json' => $run->counters_json,
            'result_json' => $run->result_json,
            'error_code' => $run->error_code,
            'last_sequence' => $run->last_sequence,
            'started_at' => $run->started_at?->toIso8601String(),
            'completed_at' => $run->completed_at?->toIso8601String(),
            'cancelled_at' => $run->cancelled_at?->toIso8601String(),
            'created_at' => $run->created_at?->toIso8601String(),
            'updated_at' => $run->updated_at?->toIso8601String(),
            'events' => $run->events
                ->map(fn (AgentRunEvent $event): array => $this->event($event))
                ->values()
                ->all(),
            'tool_executions' => $run->toolExecutions
                ->map(fn (AgentToolExecution $execution): array => $this->toolExecution($execution))
                ->values()
                ->all(),
            'planner_shadow_reports' => $run->plannerShadowReports
                ->map(fn (AgentPlannerShadowReport $report): array => $this->plannerShadowReport($report))
                ->values()
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function event(AgentRunEvent $event): array
    {
        return [
            'id' => $event->getKey(),
            'sequence' => $event->sequence,
            'type' => $event->type,
            'phase' => $event->phase,
            'locale' => $event->locale,
            'message_key' => $event->message_key,
            'message_params' => $event->message_params,
            'message' => $event->message,
            'payload_json' => $event->payload_json,
            'created_at' => $event->created_at?->toIso8601String(),
            'updated_at' => $event->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function toolExecution(AgentToolExecution $execution): array
    {
        return [
            'id' => $execution->getKey(),
            'logical_index' => $execution->logical_index,
            'tool_name' => $execution->tool_name,
            'tool_kind' => $execution->tool_kind,
            'api_route_id' => $execution->api_route_id,
            'status' => $execution->status,
            'depends_on_json' => $execution->depends_on_json,
            'arguments_json' => $execution->arguments_json,
            'result_meta_json' => $execution->result_meta_json,
            'error_code' => $execution->error_code,
            'physical_request_count' => $execution->physical_request_count,
            'latency_ms' => $execution->latency_ms,
            'started_at' => $execution->started_at?->toIso8601String(),
            'completed_at' => $execution->completed_at?->toIso8601String(),
            'created_at' => $execution->created_at?->toIso8601String(),
            'updated_at' => $execution->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function plannerShadowReport(AgentPlannerShadowReport $report): array
    {
        return [
            'id' => $report->getKey(),
            'iteration' => $report->iteration,
            'tenant_id' => $report->tenant_id,
            'project_key' => $report->project_key,
            'mode' => $report->mode,
            'status' => $report->status,
            'capability_hash' => $report->capability_hash,
            'capability_count' => $report->capability_count,
            'capability_bytes' => $report->capability_bytes,
            'candidate_tools_json' => $report->candidate_tools_json,
            'route_json' => $report->route_json,
            'classic_plan_json' => $report->classic_plan_json,
            'capability_plan_json' => $report->capability_plan_json,
            'comparison_json' => $report->comparison_json,
            'router_latency_ms' => $report->router_latency_ms,
            'planner_latency_ms' => $report->planner_latency_ms,
            'prompt_tokens' => $report->prompt_tokens,
            'completion_tokens' => $report->completion_tokens,
            'fallback_used' => $report->fallback_used,
            'error_code' => $report->error_code,
            'created_at' => $report->created_at?->toIso8601String(),
            'updated_at' => $report->updated_at?->toIso8601String(),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Widget;

use App\Agent\AgentEventStream;
use App\Agent\AgentExecutionContextFactory;
use App\Agent\AgentRunAccess;
use App\Agent\AgentRunControl;
use App\Agent\AgentRunDispatcher;
use App\Http\Middleware\ResolveWidgetKey;
use App\Models\AgentRun;
use App\Models\WidgetIdentity;
use App\Models\WidgetKey;
use App\Models\WidgetSession;
use App\Models\WidgetSessionStep;
use App\Services\Widget\WidgetOrchestratorService;
use App\Services\Widget\WidgetPiiMasker;
use App\Services\Widget\WidgetSessionResolver;
use App\Services\Widget\WidgetSnapshotValidator;
use App\Support\SupportedLocale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Durable data-retrieval turns for the public widget channel. */
final class WidgetAgentRunController extends Controller
{
    public function __construct(
        private readonly WidgetSessionResolver $sessions,
        private readonly WidgetPiiMasker $masker,
    ) {}

    public function start(
        Request $request,
        WidgetSnapshotValidator $snapshots,
        WidgetOrchestratorService $orchestrator,
        AgentExecutionContextFactory $contexts,
        AgentRunDispatcher $runs,
    ): JsonResponse {
        $data = $request->validate([
            'snapshot' => ['required', 'array'],
            'message' => ['required', 'string', 'max:'.(int) config('widget.max_message_length', 10000)],
            'page_url' => ['nullable', 'string', 'max:2048'],
        ]);
        try {
            $snapshots->assertWithinCaps($data['snapshot']);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'error' => 'snapshot_too_large',
                'message' => $exception->getMessage(),
            ], 422);
        }
        $snapshot = $snapshots->enforceSensitiveNull($snapshots->sanitizeSnapshot($data['snapshot']));
        $key = $this->key($request);
        $identity = $this->identity($request);
        $locale = $this->locale($request, $snapshot);
        $session = $orchestrator->openSession(
            $key,
            $this->nullableString($data['page_url'] ?? null) ?? $this->nullableString(data_get($snapshot, 'page.url')),
            $this->nullableString($request->header('Origin')),
            $identity,
            $locale,
        );

        return $this->dispatchTurn($session, (string) $data['message'], $contexts, $runs);
    }

    public function store(
        Request $request,
        string $session,
        AgentExecutionContextFactory $contexts,
        AgentRunDispatcher $runs,
    ): JsonResponse {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:'.(int) config('widget.max_message_length', 10000)],
        ]);
        $widgetSession = $this->sessions->findOrFail($request, $session);
        if (! in_array($widgetSession->status, [
            WidgetSession::STATUS_ACTIVE,
            WidgetSession::STATUS_WAITING_USER,
        ], true)) {
            return response()->json(['error' => 'session_not_active'], 409);
        }

        return $this->dispatchTurn($widgetSession, (string) $data['message'], $contexts, $runs);
    }

    public function events(
        Request $request,
        string $session,
        string $run,
        AgentRunAccess $access,
        AgentEventStream $stream,
    ): StreamedResponse {
        $agentRun = $this->resolveRun($request, $session, $run, $access);
        $after = max(0, (int) ($request->query('after') ?? $request->header('Last-Event-ID', 0)));

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

    public function cancel(
        Request $request,
        string $session,
        string $run,
        AgentRunAccess $access,
        AgentRunControl $control,
    ): JsonResponse {
        try {
            $agentRun = $control->cancel($this->resolveRun($request, $session, $run, $access));
        } catch (\DomainException $exception) {
            return response()->json(['error' => $exception->getMessage()], 409);
        }

        return response()->json($this->runPayload($agentRun));
    }

    public function resume(
        Request $request,
        string $session,
        string $run,
        AgentRunAccess $access,
        AgentRunControl $control,
    ): JsonResponse {
        $data = $request->validate([
            'logical_extension' => ['sometimes', 'integer', 'min:0'],
            'physical_extension' => ['required', 'integer', 'min:1'],
        ]);
        try {
            $agentRun = $control->resume(
                $this->resolveRun($request, $session, $run, $access),
                (int) ($data['logical_extension'] ?? 0),
                (int) $data['physical_extension'],
            );
        } catch (\DomainException $exception) {
            return response()->json(['error' => $exception->getMessage()], 409);
        }

        return response()->json($this->runPayload($agentRun), 202);
    }

    private function dispatchTurn(
        WidgetSession $session,
        string $message,
        AgentExecutionContextFactory $contexts,
        AgentRunDispatcher $runs,
    ): JsonResponse {
        if ($session->agentRuns()->whereNotIn('status', [
            AgentRun::STATUS_COMPLETED,
            AgentRun::STATUS_PARTIAL,
            AgentRun::STATUS_FAILED,
            AgentRun::STATUS_CANCELLED,
        ])->exists()) {
            return response()->json(['error' => 'agent_run_active'], 409);
        }
        if ($session->steps()->count() >= (int) config('widget.max_steps_per_session', 100)) {
            return response()->json(['error' => 'session_blocked'], 422);
        }

        $identity = $session->identity;
        $context = $contexts->forWidget($session->widgetKey, $identity, $session->locale);
        $step = $session->steps()->create([
            'step_index' => (int) ($session->steps()->max('step_index') ?? -1) + 1,
            'kind' => WidgetSessionStep::KIND_USER_MESSAGE,
            'args_json' => $this->masker->maskArray(['content' => $message]) ?? [],
        ]);
        $run = $runs->dispatch($context, [
            'question' => $message,
            'widget_user_step_id' => $step->id,
        ], [
            'widget_identity_id' => $identity?->id,
            'widget_session_id' => $session->id,
        ]);
        $step->forceFill(['args_json' => $this->masker->maskArray([
            'content' => $message,
            'agent_run_id' => $run->run_id,
        ])])->save();

        return response()->json([
            'session' => [
                'id' => $session->public_session_id,
                'status' => $session->status,
                'locale' => SupportedLocale::normalize($session->locale),
            ],
            'type' => 'agent_run',
            'run' => $this->runPayload($run, $session),
        ], 202);
    }

    private function resolveRun(
        Request $request,
        string $session,
        string $run,
        AgentRunAccess $access,
    ): AgentRun {
        return $access->forWidgetOrFail(
            $run,
            $this->sessions->findOrFail($request, $session),
            $this->identity($request),
        );
    }

    /** @return array<string,mixed> */
    private function runPayload(AgentRun $run, ?WidgetSession $session = null): array
    {
        $publicSession = $session?->public_session_id ?? $run->widgetSession?->public_session_id;
        $base = '/api/widget/sessions/'.$publicSession.'/agent-runs/'.$run->run_id;

        return [
            'id' => $run->run_id,
            'status' => $run->status,
            'locale' => $run->locale,
            'events_url' => $base.'/events',
            'cancel_url' => $base.'/cancel',
            'continue_url' => $base.'/continue',
            'budget' => $run->budget_json ?? [],
        ];
    }

    private function key(Request $request): WidgetKey
    {
        $key = $request->attributes->get(ResolveWidgetKey::ATTR_KEY);
        abort_unless($key instanceof WidgetKey, 401);

        return $key;
    }

    private function identity(Request $request): ?WidgetIdentity
    {
        $identity = $request->attributes->get(ResolveWidgetKey::ATTR_IDENTITY);

        return $identity instanceof WidgetIdentity ? $identity : null;
    }

    /** @param array<string,mixed> $snapshot */
    private function locale(Request $request, array $snapshot): string
    {
        $signed = $request->attributes->get(ResolveWidgetKey::ATTR_LOCALE);
        $candidate = is_string($signed) ? $signed : data_get($snapshot, 'active_context.locale');

        return SupportedLocale::normalize(is_string($candidate) ? $candidate : null);
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}

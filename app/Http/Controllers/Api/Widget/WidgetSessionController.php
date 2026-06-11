<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Widget;

use App\Http\Middleware\ResolveWidgetKey;
use App\Models\WidgetKey;
use App\Models\WidgetSession;
use App\Models\WidgetSessionStep;
use App\Services\Widget\WidgetAiToolRegistry;
use App\Services\Widget\WidgetOrchestratorService;
use App\Services\Widget\WidgetPiiMasker;
use App\Services\Widget\WidgetSnapshotValidator;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use InvalidArgumentException;

/**
 * Endpoint del loop ReAct del widget (gira dietro `widget.key`, quindi
 * tenant/project/key sono già risolti dal middleware — R30).
 *
 *   POST /api/widget/sessions/start           apre la sessione + primo turno
 *   POST /api/widget/sessions/{session}/step  turno successivo (snapshot + tool_result + msg)
 *   POST /api/widget/sessions/{session}/cancel chiude la sessione (aborted)
 *
 * `{session}` è il `public_session_id` (UUID opaco) e viene SEMPRE risolto
 * scoping sulla key chiamante: una sessione di un'altra key/tenant ⇒ 404
 * (anti-IDOR, R30). M4 aggiunge /exec-tool e /replay.
 */
final class WidgetSessionController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly WidgetPiiMasker $piiMasker,
    ) {}

    public function start(
        Request $request,
        WidgetOrchestratorService $orchestrator,
        WidgetSnapshotValidator $snapshotValidator,
    ): JsonResponse {
        $maxLen = (int) config('widget.max_message_length', 10000);
        $data = $request->validate([
            'snapshot' => ['required', 'array'],
            'message' => ['nullable', 'string', 'max:'.$maxLen],
            'page_url' => ['nullable', 'string', 'max:2048'],
        ]);

        /** @var array<string, mixed> $snapshot */
        $snapshot = $data['snapshot'];

        if ($error = $this->snapshotCapError($snapshot, $snapshotValidator)) {
            return $error;
        }

        // M5.7 — ri-sanitizzazione server-side dei testi (il BE non si fida del FE).
        $snapshot = $snapshotValidator->sanitizeSnapshot($snapshot);

        // M5.8 — campi sensitive DEVONO avere value:null (guard BE).
        $snapshot = $snapshotValidator->enforceSensitiveNull($snapshot);

        $key = $this->key($request);

        $payload = $orchestrator->start(
            key: $key,
            snapshot: $snapshot,
            userMessage: $this->nullableString($data['message'] ?? null),
            pageUrl: $this->nullableString($data['page_url'] ?? null) ?? $this->nullableString(data_get($snapshot, 'page.url')),
            origin: $this->nullableString($request->header('Origin')),
        );

        return response()->json($payload);
    }

    public function step(
        Request $request,
        string $session,
        WidgetOrchestratorService $orchestrator,
        WidgetSnapshotValidator $snapshotValidator,
    ): JsonResponse {
        $maxLen = (int) config('widget.max_message_length', 10000);
        $data = $request->validate([
            'snapshot' => ['required', 'array'],
            'message' => ['nullable', 'string', 'max:'.$maxLen],
            'tool_result' => ['nullable', 'array'],
        ]);

        /** @var array<string, mixed> $snapshot */
        $snapshot = $data['snapshot'];

        if ($error = $this->snapshotCapError($snapshot, $snapshotValidator)) {
            return $error;
        }

        // M5.7 — ri-sanitizzazione server-side dei testi (il BE non si fida del FE).
        $snapshot = $snapshotValidator->sanitizeSnapshot($snapshot);

        // M5.8 — campi sensitive DEVONO avere value:null (guard BE).
        $snapshot = $snapshotValidator->enforceSensitiveNull($snapshot);

        $widgetSession = $this->resolveSession($request, $session);

        // M5.4 — per-session rate-limit
        $sessionRl = ResolveWidgetKey::sessionRateLimited(
            $widgetSession->public_session_id,
            (int) config('widget.session_rate_limit_per_minute', 30),
        );
        if ($sessionRl !== null) {
            return $sessionRl;
        }

        // M5.5 — step cap: se la sessione ha troppi step, blocca
        $maxSteps = (int) config('widget.max_steps_per_session', 100);
        if ($widgetSession->steps()->count() >= $maxSteps) {
            $widgetSession->forceFill([
                'status' => WidgetSession::STATUS_BLOCKED,
                'blocked_reason' => 'Max steps per session exceeded.',
            ])->save();

            return response()->json([
                'error' => 'session_blocked',
                'message' => 'This session has exceeded the maximum number of steps.',
            ], 422);
        }

        if (in_array($widgetSession->status, [WidgetSession::STATUS_COMPLETED, WidgetSession::STATUS_ABORTED], true)) {
            return response()->json([
                'error' => 'session_closed',
                'message' => 'This widget session is already closed.',
            ], 409);
        }

        $payload = $orchestrator->step(
            session: $widgetSession,
            snapshot: $snapshot,
            userMessage: $this->nullableString($data['message'] ?? null),
            toolResult: is_array($data['tool_result'] ?? null) ? $data['tool_result'] : null,
        );

        return response()->json($payload);
    }

    public function cancel(Request $request, string $session): JsonResponse
    {
        $widgetSession = $this->resolveSession($request, $session);
        $widgetSession->forceFill(['status' => WidgetSession::STATUS_ABORTED])->save();

        return response()->json([
            'session' => ['id' => $widgetSession->public_session_id, 'status' => $widgetSession->status],
        ]);
    }

    /**
     * POST /api/widget/sessions/{session}/exec-tool — M4
     *
     * Esegue un tool BE (AiTool registry-driven) per la sessione corrente.
     * Il FE chiama questo endpoint quando l'orchestratore emette una tool_call
     * con is_be_tool=true. Il backend esegue la business logic tramite
     * WidgetAiToolRegistry, persiste lo step e ritorna un artifact renderizzabile.
     */
    public function execTool(
        Request $request,
        string $session,
        WidgetOrchestratorService $orchestrator,
        WidgetAiToolRegistry $aiToolRegistry,
    ): JsonResponse {
        $data = $request->validate([
            'tool' => ['required', 'string', 'max:255'],
            'args' => ['nullable', 'array'],
        ]);

        $widgetSession = $this->resolveSession($request, $session);

        // M5.4 — per-session rate-limit
        $sessionRl = ResolveWidgetKey::sessionRateLimited(
            $widgetSession->public_session_id,
            (int) config('widget.session_rate_limit_per_minute', 30),
        );
        if ($sessionRl !== null) {
            return $sessionRl;
        }

        // Verifica che la sessione sia attiva
        if (! in_array($widgetSession->status, [WidgetSession::STATUS_ACTIVE, WidgetSession::STATUS_WAITING_TOOL], true)) {
            return response()->json([
                'error' => 'session_not_active',
                'message' => 'This widget session is not active.',
            ], 409);
        }

        // #19 — stesso cap di step(): /exec-tool ripetuti NON devono far crescere
        // gli step illimitatamente (ogni call è una RAG retrieval completa).
        $maxSteps = (int) config('widget.max_steps_per_session', 100);
        if ($widgetSession->steps()->count() >= $maxSteps) {
            $widgetSession->forceFill([
                'status' => WidgetSession::STATUS_BLOCKED,
                'blocked_reason' => 'Max steps per session exceeded.',
            ])->save();

            return response()->json([
                'error' => 'session_blocked',
                'message' => 'This session has exceeded the maximum number of steps.',
            ], 422);
        }

        $tool = (string) $data['tool'];
        $args = is_array($data['args'] ?? null) ? $data['args'] : [];

        // Carica skill manifest per verificare che il tool sia abilitato
        $manifest = $orchestrator->getSkillManifest($widgetSession);
        $aiTools = array_values(array_filter((array) ($manifest['ai_tools'] ?? []), 'is_string'));
        $toolsEnabled = array_values(array_filter((array) ($manifest['tools_enabled'] ?? []), 'is_string'));

        if (! $aiToolRegistry->supports($tool, $aiTools, $toolsEnabled)) {
            return response()->json([
                'error' => 'tool_not_enabled',
                'message' => "AiTool '{$tool}' is not enabled for this skill.",
            ], 422);
        }

        try {
            $result = $aiToolRegistry->execute($tool, $args, $widgetSession);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => 'tool_execution_error',
                'message' => $e->getMessage(),
            ], 422);
        }

        // #20 — persiste SOLO il risultato. Il KIND_TOOL_CALL è già stato scritto
        // dall'orchestrator (finishWithToolCall) quando ha emesso la tool_call:
        // riscriverlo qui duplicava '[azione] …' nella history LLM e consumava il
        // cap step due volte. M5.6 — PII mascherata PRIMA del salvataggio.
        $nextIndex = (int) ($widgetSession->steps()->max('step_index') ?? -1) + 1;
        $resultArgs = [
            'ok' => $result['has_results'],
            'tool' => $tool,
            'artifact' => $result['artifact'],
        ];
        $widgetSession->steps()->create([
            'step_index' => $nextIndex,
            'kind' => WidgetSessionStep::KIND_TOOL_RESULT,
            'tool' => $tool,
            'args_json' => $this->piiMasker->maskArray($resultArgs) ?? $resultArgs,
        ]);

        return response()->json($result);
    }

    /**
     * GET /api/widget/sessions/{session}/replay — M5.9
     *
     * Ritorna gli step della sessione con PII mascherata (difesa in profondità:
     * lo step è già mascherato al save, ma ri-mascheriamo in lettura per sicurezza).
     * Scope alla key chiamante (anti-IDOR, R30): sessione di altra key → 404.
     * Sessione vuota → array vuoto (mai null).
     */
    public function replay(Request $request, string $session): JsonResponse
    {
        $widgetSession = $this->resolveSession($request, $session);

        $steps = $widgetSession->steps()
            ->orderBy('step_index')
            ->get();

        $masked = $steps->map(function (WidgetSessionStep $step): array {
            return [
                'step_index' => $step->step_index,
                'kind' => $step->kind,
                'tool' => $step->tool,
                'args_json' => $this->piiMasker->maskArray($step->args_json),
                'diagnostic_json' => $this->piiMasker->maskArray($step->diagnostic_json),
            ];
        })->values()->all();

        return response()->json(['steps' => $masked]);
    }

    /**
     * Risolve la sessione SOLO se appartiene alla key chiamante (anti-IDOR, R30).
     */
    private function resolveSession(Request $request, string $publicId): WidgetSession
    {
        // R30 — scope to the active tenant (set FROM the key by
        // ResolveWidgetKey) AND to the calling key. forTenant() is the
        // primary tenant boundary; widget_key_id is the anti-IDOR guard so
        // one key can't drive another key's session within the same tenant.
        return WidgetSession::query()
            ->forTenant($this->tenants->current())
            ->where('public_session_id', $publicId)
            ->where('widget_key_id', $this->key($request)->id)
            ->firstOrFail();
    }

    private function key(Request $request): WidgetKey
    {
        /** @var WidgetKey $key */
        $key = $request->attributes->get(ResolveWidgetKey::ATTR_KEY);

        return $key;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function snapshotCapError(array $snapshot, WidgetSnapshotValidator $validator): ?JsonResponse
    {
        try {
            $validator->assertWithinCaps($snapshot);

            return null;
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'error' => 'snapshot_too_large',
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}

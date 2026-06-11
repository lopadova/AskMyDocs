<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Models\WidgetKey;
use App\Models\WidgetSession;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * M6.3 — Admin API for inspecting widget sessions (tenant-scoped, R30).
 *
 * Actions: index (list sessions, optionally filtered by key) / show (detail with steps).
 * Replay reuses the M5 widget replay endpoint but scoping is admin-side:
 * the admin calls this to replay session content in the admin UI.
 */
final class WidgetSessionAdminController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    /** List sessions for the current tenant, optionally filtered by widget_key_id. */
    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->tenantContext->current();
        $query = WidgetSession::query()
            ->where('tenant_id', $tenantId)
            ->with('widgetKey:id,public_key,label')
            // #27 — un solo COUNT aggregato per riga invece di lazy-load dell'intera
            // collection di step (con i longText snapshot) solo per contarli.
            ->withCount('steps');

        if ($request->filled('widget_key_id')) {
            $query->where('widget_key_id', (int) $request->input('widget_key_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        // #27 — clampa per_page: ?per_page=1000000 idraterebbe milioni di righe
        // (memory exhaustion, R3).
        $perPage = min(max((int) $request->input('per_page', 25), 1), 100);

        $rows = $query->orderByDesc('created_at')
            ->paginate(perPage: $perPage)
            ->through(fn (WidgetSession $s): array => $this->serializeList($s));

        return response()->json([
            'data' => $rows->items(),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'per_page' => $rows->perPage(),
                'total' => $rows->total(),
            ],
        ]);
    }

    /** Show session detail with steps. */
    public function show(int $id): JsonResponse
    {
        $session = $this->findForTenant($id);
        $session->load(['steps', 'widgetKey:id,public_key,label']);

        return response()->json([
            'data' => $this->serializeDetail($session),
        ]);
    }

    /** Find a WidgetSession scoped to the current tenant or 404. */
    private function findForTenant(int $id): WidgetSession
    {
        $tenantId = $this->tenantContext->current();

        $session = WidgetSession::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->first();

        if ($session === null) {
            throw new NotFoundHttpException('Widget session not found.');
        }

        return $session;
    }

    /** Serialize session for list view (no steps payload). */
    private function serializeList(WidgetSession $s): array
    {
        return [
            'id' => $s->id,
            'public_session_id' => $s->public_session_id,
            'widget_key' => $s->widgetKey ? [
                'id' => $s->widgetKey->id,
                'label' => $s->widgetKey->label,
                'public_key' => $s->widgetKey->public_key,
            ] : null,
            'status' => $s->status,
            'skill' => $s->skill,
            'mission' => $s->mission,
            'origin' => $s->origin,
            // #27 — usa il COUNT aggregato (withCount), non $s->steps->count()
            // che lazy-loaderebbe l'intera collection.
            'steps_count' => (int) ($s->steps_count ?? 0),
            'created_at' => $s->created_at->toIso8601String(),
            'updated_at' => $s->updated_at->toIso8601String(),
        ];
    }

    /** Serialize session with full steps for detail view. */
    private function serializeDetail(WidgetSession $s): array
    {
        return [
            'id' => $s->id,
            'public_session_id' => $s->public_session_id,
            'widget_key' => $s->widgetKey ? [
                'id' => $s->widgetKey->id,
                'label' => $s->widgetKey->label,
                'public_key' => $s->widgetKey->public_key,
            ] : null,
            'status' => $s->status,
            'skill' => $s->skill,
            'mission' => $s->mission,
            'page_url' => $s->page_url,
            'origin' => $s->origin,
            'summary' => $s->summary,
            'blocked_reason' => $s->blocked_reason,
            'meta' => $s->meta,
            'steps' => $s->steps->map(fn ($step): array => [
                'id' => $step->id,
                'kind' => $step->kind,
                'tool' => $step->tool,
                'args_json' => $step->args_json,
                'tokens_in' => $step->tokens_in,
                'tokens_out' => $step->tokens_out,
                'latency_ms' => $step->latency_ms,
                'created_at' => $step->created_at->toIso8601String(),
            ])->values()->all(),
            'created_at' => $s->created_at->toIso8601String(),
            'updated_at' => $s->updated_at->toIso8601String(),
        ];
    }
}
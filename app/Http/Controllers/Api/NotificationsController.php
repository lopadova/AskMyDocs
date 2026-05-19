<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\NotificationEvent;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/**
 * v8.0/W1.4 — REST surface backing the React notification bell +
 * `/admin/notifications` panel.
 *
 * All actions are scoped to the currently-authenticated user AND
 * the active TenantContext. Cross-user / cross-tenant reads return
 * 404 (NOT 403) so callers cannot enumerate ids that belong to
 * other users.
 *
 * Endpoints:
 *   GET    /api/notifications                ?state=unread|read|dismissed|all
 *                                           ?event_type=kb_doc_created|...
 *                                           ?page (paginate, 20 per page)
 *   GET    /api/notifications/unread-count
 *   POST   /api/notifications/{id}/mark-read
 *   POST   /api/notifications/{id}/dismiss
 *   POST   /api/notifications/mark-all-read
 *
 * Tenant-wide rows (`user_id IS NULL`) are intentionally NOT
 * returned on the per-user feed — a future endpoint
 * `/api/notifications/system` will surface them to admin roles
 * (parked for W4 when the decision-debt digest publisher lands).
 */
final class NotificationsController extends Controller
{
    public function index(Request $request, TenantContext $tenants): JsonResponse
    {
        $validated = $request->validate([
            'state' => ['nullable', Rule::in(['unread', 'read', 'dismissed', 'all'])],
            'event_type' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $state = $validated['state'] ?? 'unread';
        $perPage = (int) ($validated['per_page'] ?? 20);

        $query = $this->ownedQuery($request, $tenants);

        if (! empty($validated['event_type'])) {
            $query->where('event_type', $validated['event_type']);
        }

        match ($state) {
            'unread' => $query->whereNull('read_at')->whereNull('dismissed_at'),
            'read' => $query->whereNotNull('read_at')->whereNull('dismissed_at'),
            'dismissed' => $query->whereNotNull('dismissed_at'),
            'all' => $query,
        };

        $page = $query->orderByDesc('created_at')->paginate($perPage);

        return response()->json([
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'state' => $state,
            ],
        ]);
    }

    public function unreadCount(Request $request, TenantContext $tenants): JsonResponse
    {
        $count = $this->ownedQuery($request, $tenants)
            ->whereNull('read_at')
            ->whereNull('dismissed_at')
            ->count();

        return response()->json(['unread_count' => $count]);
    }

    public function markRead(Request $request, TenantContext $tenants, int $id): JsonResponse
    {
        $row = $this->ownedQuery($request, $tenants)->find($id);
        if ($row === null) {
            return response()->json(['error' => 'not_found'], 404);
        }
        if ($row->read_at === null) {
            $row->read_at = now();
            $row->save();
        }
        return response()->json(['data' => $row->fresh()]);
    }

    public function dismiss(Request $request, TenantContext $tenants, int $id): JsonResponse
    {
        $row = $this->ownedQuery($request, $tenants)->find($id);
        if ($row === null) {
            return response()->json(['error' => 'not_found'], 404);
        }
        $changed = false;
        if ($row->read_at === null) {
            $row->read_at = now();
            $changed = true;
        }
        if ($row->dismissed_at === null) {
            $row->dismissed_at = now();
            $changed = true;
        }
        if ($changed) {
            $row->save();
        }
        return response()->json(['data' => $row->fresh()]);
    }

    public function markAllRead(Request $request, TenantContext $tenants): JsonResponse
    {
        $affected = $this->ownedQuery($request, $tenants)
            ->whereNull('read_at')
            ->whereNull('dismissed_at')
            ->update(['read_at' => now()]);

        return response()->json(['marked_read' => $affected]);
    }

    /**
     * Base query scoped to (current tenant, current authenticated
     * user). Tenant-wide rows (`user_id IS NULL`) are NOT included.
     */
    private function ownedQuery(Request $request, TenantContext $tenants): \Illuminate\Database\Eloquent\Builder
    {
        $user = $request->user();
        if ($user === null) {
            abort(401);
        }

        return NotificationEvent::query()
            ->where('tenant_id', $tenants->current())
            ->where('user_id', $user->id);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\NotificationEvent;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
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
        // R21 — atomic conditional update. The previous load-then-save
        // pattern let two concurrent mark-read calls both observe
        // `read_at = null` and then save different timestamps, so the
        // documented idempotency contract held only for sequential
        // replays. Pushing the `read_at IS NULL` predicate into the
        // UPDATE makes the first writer the only writer; subsequent
        // calls become no-ops at the DB level and return the now-
        // stamped row unchanged.
        $now = now();
        $updated = $this->ownedQuery($request, $tenants)
            ->whereKey($id)
            ->whereNull('read_at')
            ->update(['read_at' => $now]);

        $row = $this->ownedQuery($request, $tenants)->find($id);
        if ($row === null) {
            return response()->json(['error' => 'not_found'], 404);
        }
        return response()->json(['data' => $row, 'changed' => $updated > 0]);
    }

    public function dismiss(Request $request, TenantContext $tenants, int $id): JsonResponse
    {
        // R21 — single atomic UPDATE. The previous draft issued two
        // separate conditional updates (one for read_at, one for
        // dismissed_at) which could interleave with a concurrent
        // mark-read on the same row and leave the two timestamps
        // mismatched (read_at from the racing call, dismissed_at from
        // this one). COALESCE preserves any pre-existing read_at while
        // stamping dismissed_at + read_at together in one round-trip
        // when either column is null; the WHERE guard turns a replay
        // into a no-op at the DB level.
        //
        // Embedding the timestamp via Carbon's `toDateTimeString()` is
        // safe from injection: the format is the fixed
        // `YYYY-MM-DD HH:MM:SS` string, no caller input, no SQL
        // meta-characters. Both SQLite + PostgreSQL accept this literal
        // in a timestamp column.
        $nowSql = now()->toDateTimeString();
        $this->ownedQuery($request, $tenants)
            ->whereKey($id)
            ->where(function ($q) {
                $q->whereNull('read_at')->orWhereNull('dismissed_at');
            })
            ->update([
                'read_at' => DB::raw("COALESCE(read_at, '{$nowSql}')"),
                'dismissed_at' => DB::raw("COALESCE(dismissed_at, '{$nowSql}')"),
            ]);

        $row = $this->ownedQuery($request, $tenants)->find($id);
        if ($row === null) {
            return response()->json(['error' => 'not_found'], 404);
        }
        return response()->json(['data' => $row]);
    }

    public function markAllRead(Request $request, TenantContext $tenants): JsonResponse
    {
        // Copilot iter-2 #3: scope bulk to the same event_type filter
        // the FE shows in the panel; otherwise a tenant viewing only
        // `kb_doc_modified` and clicking "Mark all read" silently
        // marks unrelated unread rows (e.g. `kb_canonical_promoted`)
        // as read. The FE always forwards its current filter.
        $validated = $request->validate([
            'event_type' => ['nullable', 'string', 'max:120'],
        ]);

        $query = $this->ownedQuery($request, $tenants)
            ->whereNull('read_at')
            ->whereNull('dismissed_at');

        if (! empty($validated['event_type'])) {
            $query->where('event_type', $validated['event_type']);
        }

        $affected = $query->update(['read_at' => now()]);

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

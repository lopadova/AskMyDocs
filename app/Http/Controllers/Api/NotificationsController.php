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
            // Copilot iter-5 #3 — max aligned with the DB column
            // `string('event_type', 64)`. The previous max of 120
            // would never trigger 422 for too-long input but the
            // resulting WHERE clause could never match a row.
            'event_type' => ['nullable', 'string', 'max:64'],
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

        // Copilot iter-3 #2 — secondary `id DESC` tie-breaker keeps the
        // page boundary deterministic when multiple events share the
        // same `created_at` (PostgreSQL is free to return tied rows in
        // any order otherwise, which can duplicate or skip rows across
        // page boundaries).
        $page = $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'data' => array_map(fn ($row) => $this->presentRow($row), $page->items()),
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

    /**
     * Copilot iter-3 #5 + iter-4 #1 — distinct event_types present in
     * THIS user's feed (current tenant). The FE event-type dropdown
     * derives its options from this list (R18 — derive from DB, not
     * from a literal subset) so newly-shipped or rare event types
     * become discoverable in the filter without a FE redeploy.
     *
     * Ordering matches the natural alpha sort of the underlying
     * column. Cache-friendly: 1 distinct query per request, max ~10
     * event types per tenant in practice.
     */
    public function eventTypes(Request $request, TenantContext $tenants): JsonResponse
    {
        $types = $this->ownedQuery($request, $tenants)
            ->select('event_type')
            ->distinct()
            ->orderBy('event_type')
            ->pluck('event_type')
            ->all();

        return response()->json(['data' => $types]);
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
        return response()->json(['data' => $this->presentRow($row), 'changed' => $updated > 0]);
    }

    public function dismiss(Request $request, TenantContext $tenants, int $id): JsonResponse
    {
        // R21 — single atomic UPDATE. The previous draft issued two
        // separate conditional updates (one for read_at, one for
        // dismissed_at) which could interleave with a concurrent
        // mark-read on the same row and leave the two timestamps
        // mismatched. COALESCE preserves any pre-existing read_at
        // while stamping dismissed_at + read_at together in one
        // round-trip when either column is null; the WHERE guard
        // turns a replay into a no-op at the DB level.
        //
        // Copilot iter-5 #9 — use proper PDO bindings instead of
        // string-interpolating the timestamp into raw SQL. Even
        // though Carbon's `toDateTimeString()` output is safe by
        // construction, bound parameters are the canonical pattern:
        // safer under future refactor (no risk a caller-controlled
        // value slips into the interpolation) and portable across
        // drivers without quoting differences.
        $user = $request->user();
        if ($user === null) {
            abort(401);
        }
        $tenantId = $tenants->current();
        $now = now()->toDateTimeString();

        // Copilot iter-6 #1 — also stamp `updated_at` so the row's
        // Eloquent-managed timestamp stays in lockstep with markRead /
        // markAllRead (both of which update it via the query builder).
        // Without this, audit traces relying on `updated_at` to detect
        // the dismiss event would silently miss it.
        DB::update(
            'UPDATE notification_events '.
            'SET read_at = COALESCE(read_at, ?), dismissed_at = COALESCE(dismissed_at, ?), '.
            'updated_at = ? '.
            'WHERE id = ? AND tenant_id = ? AND user_id = ? '.
            'AND (read_at IS NULL OR dismissed_at IS NULL)',
            [$now, $now, $now, $id, $tenantId, $user->id],
        );

        $row = $this->ownedQuery($request, $tenants)->find($id);
        if ($row === null) {
            return response()->json(['error' => 'not_found'], 404);
        }
        return response()->json(['data' => $this->presentRow($row)]);
    }

    public function markAllRead(Request $request, TenantContext $tenants): JsonResponse
    {
        // Copilot iter-2 #3: scope bulk to the same event_type filter
        // the FE shows in the panel; otherwise a tenant viewing only
        // `kb_doc_modified` and clicking "Mark all read" silently
        // marks unrelated unread rows (e.g. `kb_canonical_promoted`)
        // as read. The FE always forwards its current filter.
        $validated = $request->validate([
            // Copilot iter-5 #3 — aligned with DB column length (64).
            'event_type' => ['nullable', 'string', 'max:64'],
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

    /**
     * Copilot iter-3 #1 — shape a NotificationEvent for the FE feed
     * without leaking forensic delivery metadata. The full
     * `channel_dispatch_log` carries per-channel `{channel, status,
     * at, error}` entries that on the email/webhook channels include
     * the user's email address + the configured webhook URL — both
     * inappropriate to ship to the user-facing JSON feed. `tenant_id`
     * + `user_id` are also redundant: every row in the response is by
     * construction owned by the calling user in their current tenant.
     *
     * The FE only needs the rendering-relevant columns. A future
     * admin-only `/api/admin/notifications/{id}/audit` endpoint can
     * expose the full row to operators when forensic inspection is
     * needed (parked for W4).
     *
     * @return array<string, mixed>
     */
    private function presentRow(NotificationEvent $row): array
    {
        return [
            'id' => $row->id,
            'event_type' => $row->event_type,
            'payload' => $row->payload ?? [],
            'created_at' => optional($row->created_at)->toIso8601String(),
            'read_at' => optional($row->read_at)->toIso8601String(),
            'dismissed_at' => optional($row->dismissed_at)->toIso8601String(),
        ];
    }
}

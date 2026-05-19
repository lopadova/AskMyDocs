<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\NotificationEvent;
use App\Models\NotificationPreference;
use App\Notifications\ChannelRegistry;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * v8.0/W2.2 — REST surface backing the React
 * `NotificationPreferencesGrid` (one row per event_type × one column
 * per channel, with bulk row/column enable + a single Save action).
 *
 * Endpoints:
 *   GET  /api/notifications/preferences
 *   PUT  /api/notifications/preferences
 *
 * Authorisation: same Sanctum cookie surface as the rest of the
 * notification feed. R30 — every read + write is scoped to the
 * (tenant_id, user_id) pair derived from the active TenantContext +
 * the authenticated user. Cross-tenant probes silently return an
 * empty `preferences` list (200) by virtue of the scope filter,
 * never a 403/404 — the existence of a tenant_id is never echoed
 * back to a non-member.
 *
 * Response shape (index):
 *   {
 *     "event_types": [...],         // BE source-of-truth (R18)
 *     "channels": [...],            // every channel name the model
 *                                   // knows about (6 today)
 *     "registered_channels": [...], // subset with a live adapter
 *                                   // bound by NotificationServiceProvider;
 *                                   // FE renders un-registered cells
 *                                   // as visibly disabled so the user
 *                                   // sees WHY a channel cannot be
 *                                   // enabled (operator must wire the
 *                                   // webhook URL first).
 *     "defaults": { channel => bool, ... },  // first-visit defaults
 *     "preferences": [
 *       { "event_type": ..., "channel": ..., "enabled": true|false }
 *     ]
 *   }
 *
 * Update body:
 *   {
 *     "preferences": [
 *       { "event_type": ..., "channel": ..., "enabled": true|false }, ...
 *     ]
 *   }
 *
 * Each row in `preferences` validates `event_type` against
 * `NotificationEvent::eventTypes()` and `channel` against
 * `NotificationPreference::availableChannels()`. The update is
 * idempotent — re-PUTting the same body is a no-op at the DB level
 * (upsert on the composite unique `(tenant_id, user_id, event_type,
 * channel)`).
 */
final class NotificationPreferencesController extends Controller
{
    public function __construct(private readonly ChannelRegistry $channels)
    {
    }

    public function index(Request $request, TenantContext $tenants): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            abort(401);
        }

        $rows = NotificationPreference::query()
            ->where('tenant_id', $tenants->current())
            ->where('user_id', $user->id)
            ->orderBy('event_type')
            ->orderBy('channel')
            ->get(['event_type', 'channel', 'enabled']);

        return response()->json($this->shape($rows));
    }

    public function update(Request $request, TenantContext $tenants): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            abort(401);
        }

        // R18 — derive `Rule::in` allow-lists from the model
        // sources-of-truth, never from a literal FE-mirrored array.
        $allowedEventTypes = NotificationEvent::eventTypes();
        $allowedChannels = NotificationPreference::availableChannels();

        $validated = $request->validate([
            // Hard cap matches the natural ceiling (5 event_types × 6
            // channels = 30) with comfortable slack for future event
            // types; protects the upsert loop from a pathological
            // payload.
            'preferences' => ['required', 'array', 'max:100'],
            'preferences.*.event_type' => ['required', 'string', Rule::in($allowedEventTypes)],
            'preferences.*.channel' => ['required', 'string', Rule::in($allowedChannels)],
            'preferences.*.enabled' => ['required', 'boolean'],
        ]);

        $tenantId = $tenants->current();
        $userId = (int) $user->id;

        DB::transaction(function () use ($validated, $tenantId, $userId): void {
            // Dedup the incoming list by (event_type, channel) so a
            // payload with two contradictory rows for the same cell
            // settles deterministically on the LAST occurrence — the
            // FE never sends duplicates today, but the dedup keeps
            // the upsert payload predictable under future use-cases
            // (CLI tooling, scripted seeding).
            $byCell = [];
            foreach ($validated['preferences'] as $pref) {
                $key = $pref['event_type'].'|'.$pref['channel'];
                $byCell[$key] = $pref;
            }

            if ($byCell === []) {
                return;
            }

            // Atomic single-statement upsert keyed on the composite
            // unique `(tenant_id, user_id, event_type, channel)`
            // (Copilot iter-2: `updateOrCreate()` inside a loop is a
            // select+write per row and can race two concurrent PUTs
            // into a unique-constraint violation; the single
            // INSERT...ON CONFLICT DO UPDATE is collision-proof).
            $now = now();
            $rows = [];
            foreach ($byCell as $pref) {
                $rows[] = [
                    'tenant_id' => $tenantId,
                    'user_id' => $userId,
                    'event_type' => $pref['event_type'],
                    'channel' => $pref['channel'],
                    'enabled' => (bool) $pref['enabled'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            NotificationPreference::query()->upsert(
                $rows,
                ['tenant_id', 'user_id', 'event_type', 'channel'],
                ['enabled', 'updated_at'],
            );
        });

        // Return the freshly-saved state so the FE TanStack Query
        // cache is updated with the canonical DB view (any row the
        // user did NOT include in the body keeps its existing
        // value, which is the additive semantics the grid relies on).
        $rows = NotificationPreference::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->orderBy('event_type')
            ->orderBy('channel')
            ->get(['event_type', 'channel', 'enabled']);

        return response()->json($this->shape($rows));
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, NotificationPreference>  $rows
     * @return array<string, mixed>
     */
    private function shape($rows): array
    {
        return [
            'event_types' => NotificationEvent::eventTypes(),
            'channels' => NotificationPreference::availableChannels(),
            'registered_channels' => $this->channels->registered(),
            'defaults' => (array) config('askmydocs.notifications.default_channel_preferences', []),
            'preferences' => $rows->map(fn ($r) => [
                'event_type' => $r->event_type,
                'channel' => $r->channel,
                'enabled' => (bool) $r->enabled,
            ])->values()->all(),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Models\NotificationEvent;
use App\Models\NotificationPreference;
use App\Models\NotificationTenantDefault;
use App\Notifications\ChannelRegistry;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * v8.0/W2.3 — REST surface backing the React
 * `AdminNotificationDefaultsGrid` at `/app/admin/notifications/defaults`.
 *
 * Per-tenant defaults are the baseline `NotificationPreferencesInitializer`
 * applies to brand-new users on admin add-user (and Phase B2 sign-up /
 * invite-acceptance). Edited by super-admins only.
 *
 * Endpoints:
 *   GET  /api/admin/notifications/defaults
 *   PUT  /api/admin/notifications/defaults
 *
 * RBAC: route group already gates on `role:admin|super-admin`. This
 * controller adds an in-method `hasRole('super-admin')` check so
 * regular admins can read the grid but cannot mutate tenant baselines
 * (mirrors the `UserController::syncRoles()` pattern from PR #18).
 *
 * R30: every read + write scoped by `TenantContext::current()`. The
 * FE never sends `tenant_id` — it is always the active tenant.
 */
final class AdminNotificationDefaultsController extends Controller
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

        $rows = NotificationTenantDefault::query()
            ->forTenant($tenants->current())
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
        if (! method_exists($user, 'hasRole') || ! $user->hasRole('super-admin')) {
            abort(403, 'Only super-admins can mutate tenant defaults.');
        }

        $allowedEventTypes = NotificationEvent::eventTypes();
        $allowedChannels = NotificationPreference::availableChannels();

        $validated = $request->validate([
            'defaults' => ['required', 'array', 'max:100'],
            'defaults.*.event_type' => ['required', 'string', Rule::in($allowedEventTypes)],
            'defaults.*.channel' => ['required', 'string', Rule::in($allowedChannels)],
            'defaults.*.enabled' => ['required', 'boolean'],
        ]);

        $tenantId = $tenants->current();

        DB::transaction(function () use ($validated, $tenantId): void {
            // Dedup `(event_type, channel)` deterministically — last
            // occurrence wins. The FE never sends duplicates today;
            // dedup keeps the upsert predictable for future scripted
            // seeding paths (CLI, fixture loaders).
            $byCell = [];
            foreach ($validated['defaults'] as $row) {
                $key = $row['event_type'].'|'.$row['channel'];
                $byCell[$key] = $row;
            }

            if ($byCell === []) {
                return;
            }

            $now = now();
            $rows = [];
            foreach ($byCell as $row) {
                $rows[] = [
                    'tenant_id' => $tenantId,
                    'event_type' => $row['event_type'],
                    'channel' => $row['channel'],
                    'enabled' => (bool) $row['enabled'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            NotificationTenantDefault::query()->upsert(
                $rows,
                ['tenant_id', 'event_type', 'channel'],
                ['enabled', 'updated_at'],
            );
        });

        $rows = NotificationTenantDefault::query()
            ->forTenant($tenantId)
            ->orderBy('event_type')
            ->orderBy('channel')
            ->get(['event_type', 'channel', 'enabled']);

        return response()->json($this->shape($rows));
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, NotificationTenantDefault>  $rows
     * @return array<string, mixed>
     */
    private function shape($rows): array
    {
        return [
            'event_types' => NotificationEvent::eventTypes(),
            'channels' => NotificationPreference::availableChannels(),
            'registered_channels' => $this->channels->registered(),
            'platform_defaults' => (array) config('askmydocs.notifications.default_channel_preferences', []),
            'defaults' => $rows->map(fn ($r) => [
                'event_type' => $r->event_type,
                'channel' => $r->channel,
                'enabled' => (bool) $r->enabled,
            ])->values()->all(),
        ];
    }
}

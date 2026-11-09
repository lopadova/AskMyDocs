<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Agent\AgentRealtime;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;

final class AgentRealtimeController
{
    public function config(Request $request, TenantContext $tenants, AgentRealtime $realtime): JsonResponse
    {
        $connection = config('broadcasting.connections.reverb');

        return response()->json([
            'enabled' => $realtime->enabled(),
            'key' => $realtime->enabled() ? $connection['key'] : null,
            'host' => $connection['options']['host'],
            'port' => $connection['options']['port'],
            'scheme' => $connection['options']['scheme'],
            'channel' => AgentRealtime::channel($tenants->current(), (int) $request->user()->id),
            'auth_endpoint' => '/agent-transport/auth',
        ])->header('Cache-Control', 'no-store');
    }

    public function auth(Request $request, TenantContext $tenants, AgentRealtime $realtime): mixed
    {
        abort_unless($realtime->enabled(), 503);
        $validated = $request->validate([
            'socket_id' => ['required', 'string', 'regex:/^\d+\.\d+$/'],
            'channel_name' => ['required', 'string', 'max:200'],
        ]);
        $expected = 'private-'.AgentRealtime::channel($tenants->current(), (int) $request->user()->id);
        abort_unless(hash_equals($expected, $validated['channel_name']), 403);

        return Broadcast::connection('reverb')->auth($request);
    }
}

<?php

declare(strict_types=1);

namespace App\Agent;

use App\Models\AgentRun;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Reverb carries invalidations only. The authorised durable log owns the data. */
final class AgentRealtime
{
    public static function channel(string $tenant, int $userId): string
    {
        return "agent.activity.{$tenant}.{$userId}";
    }

    public function enabled(): bool
    {
        return config('broadcasting.default') === 'reverb'
            && filled(config('broadcasting.connections.reverb.key'));
    }

    public function websocketOrigin(): ?string
    {
        $host = config('broadcasting.connections.reverb.options.host');
        $port = (int) config('broadcasting.connections.reverb.options.port');
        if (! $this->enabled() || ! is_string($host) || ! preg_match('/^[a-zA-Z0-9.-]+$/D', $host) || $port < 1 || $port > 65535) {
            return null;
        }
        $scheme = config('broadcasting.connections.reverb.options.scheme') === 'https' ? 'wss' : 'ws';

        return "{$scheme}://{$host}:{$port}";
    }

    public function notifyAfterCommit(AgentRun $run, int $sequence): void
    {
        if (! $this->enabled() || $run->channel !== 'chat' || $run->actor_type !== 'user' || $run->user_id === null) {
            return;
        }

        $channel = 'private-'.self::channel($run->tenant_id, (int) $run->user_id);
        $payload = ['run_id' => $run->run_id, 'sequence' => $sequence, 'conversation_id' => $run->conversation_id];
        DB::afterCommit(static function () use ($channel, $payload): void {
            try {
                Broadcast::connection('reverb')->broadcast([$channel], 'agent.run.updated', $payload);
            } catch (\Throwable $exception) {
                // A failed notification never loses an event or rolls back a run.
                Log::warning('Agent Reverb notification unavailable', ['exception' => $exception::class]);
            }
        });
    }
}

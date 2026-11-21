<?php

declare(strict_types=1);

namespace App\Agent;

use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Cache;

/** Enable new concurrent turns only after independent worker pools poll both lanes. */
final class AgentSessionWorkers
{
    private int $nextHeartbeat = 0;

    public function observe(Looping $event): void
    {
        if (! config('agent.concurrent_session') || time() < $this->nextHeartbeat
            || $event->connectionName !== config('queue.default')) {
            return;
        }
        $queues = explode(',', $event->queue);
        $session = config('agent.session_queue');
        $research = config('agent.queue');
        if ($queues === [$session] && $session !== $research) {
            $lane = 'session';
        } elseif (in_array($research, $queues, true) && ! in_array($session, $queues, true)) {
            $lane = 'research';
        } else {
            return;
        }

        Cache::put($this->key($lane), true, max(120, (int) config('agent.job_timeout_seconds') + 60));
        $this->nextHeartbeat = time() + 30;
    }

    public function available(): bool
    {
        return config('agent.concurrent_session') && (! config('agent.verify_session_workers')
            || (Cache::has($this->key('session')) && Cache::has($this->key('research'))));
    }

    private function key(string $lane): string
    {
        return implode(':', ['agent-session-workers', config('agent.diagnostics.release'), config('queue.default'),
            config('agent.session_queue'), config('agent.queue'), $lane]);
    }
}

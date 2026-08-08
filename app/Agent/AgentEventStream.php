<?php

declare(strict_types=1);

namespace App\Agent;

use App\Models\AgentRun;
use App\Models\AgentRunEvent;
use Generator;

final readonly class AgentEventStream
{
    public function __construct(private AgentEventPublisher $publisher) {}

    /** @return Generator<int,string> */
    public function frames(AgentRun $run, int $afterSequence): Generator
    {
        $cursor = max(0, $afterSequence);
        $pollMs = max(10, (int) config('agent.events.poll_ms', 100));
        $deadline = microtime(true) + max(0, (float) config('agent.events.stream_seconds', 25));

        do {
            $events = AgentRunEvent::query()
                ->where('agent_run_id', $run->id)
                ->where('sequence', '>', $cursor)
                ->orderBy('sequence')
                ->limit(100)
                ->get();

            foreach ($events as $event) {
                $event->setRelation('run', $run);
                $cursor = $event->sequence;
                $data = json_encode($this->publisher->serialize($event), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                yield "id: {$cursor}\nevent: {$event->type}\ndata: {$data}\n\n";
            }

            $run->refresh();
            if ($run->isTerminal() && $cursor >= $run->last_sequence) {
                break;
            }

            if ($events->isEmpty()) {
                yield ": keep-alive\n\n";
            }

            if (connection_aborted()) {
                break;
            }

            usleep($pollMs * 1000);
        } while (microtime(true) < $deadline);
    }
}

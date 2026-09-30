<?php

declare(strict_types=1);

namespace App\Agent;

use App\Models\AgentRun;
use App\Models\AgentRunEvent;
use Generator;

final readonly class AgentEventStream
{
    public function __construct(private AgentEventPublisher $publisher, private AgentMessageCatalog $messages) {}

    /** @return Generator<int,string> */
    public function frames(AgentRun $run, int $afterSequence): Generator
    {
        $cursor = max(0, $afterSequence);
        $pollMs = max(10, (int) config('agent.events.poll_ms', 100));
        $deadline = microtime(true) + max(0, (float) config('agent.events.stream_seconds', 25));
        $sentTerminal = false;

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
                $sentTerminal = $sentTerminal || in_array($event->type, ['run.failed', 'run.cancelled', 'run.completed', 'run.partial'], true);
            }

            $run->refresh();
            // Drain the actual log, not a counter that an old worker may have
            // rewound. In particular, do not stop after only the first 100 frames.
            if ($run->isTerminal() && ! $run->events()->where('sequence', '>', $cursor)->exists()) {
                if (! $sentTerminal && in_array($run->status, [AgentRun::STATUS_FAILED, AgentRun::STATUS_CANCELLED], true)) {
                    // A crash may persist failure before publishing its event.
                    // This read-only transport snapshot closes the client stream;
                    // it does not rewrite history, rerun research or expose errors/payloads.
                    $type = 'run.'.$run->status;
                    $copy = $this->messages->format($run->locale, $type);
                    $cursor = max($cursor, (int) $run->last_sequence) + 1;
                    $data = json_encode([
                        'run_id' => $run->run_id, 'sequence' => $cursor, 'type' => $type, 'phase' => 'run',
                        ...$copy, 'progress' => null, 'can_cancel' => false,
                        'data' => ['status_snapshot' => true], 'created_at' => $run->updated_at?->toIso8601String(),
                    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                    yield "id: {$cursor}\nevent: {$type}\ndata: {$data}\n\n";
                }
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

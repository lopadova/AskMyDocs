<?php

declare(strict_types=1);

namespace App\Agent;

use App\Models\AgentRun;

/** Bounded replay; no waiting, polling loop, or open PHP worker. */
final readonly class AgentEventLog
{
    public function __construct(private AgentEventPublisher $publisher, private AgentMessageCatalog $messages) {}

    public function read(AgentRun $run, int $after): array
    {
        $after = max(0, $after);
        $rows = $run->events()->where('sequence', '>', $after)->orderBy('sequence')->limit(101)->get();
        $hasMore = $rows->count() > 100;
        $events = $rows->take(100)->map(function ($event) use ($run): array {
            $event->setRelation('run', $run);

            return $this->publisher->serialize($event);
        })->values()->all();
        $run->refresh();
        $cursor = $events === [] ? $after : (int) end($events)['sequence'];
        // Recover an interrupted publisher without inventing tool results.
        // Also cover events committed while the bounded page was being read.
        $hasMore = $hasMore || $run->events()->where('sequence', '>', $cursor)->exists();
        if (! $hasMore && ($run->isTerminal() || $run->status === AgentRun::STATUS_AWAITING_CONFIRMATION)) {
            $type = 'run.'.$run->status;
            if (! in_array($type, array_column($events, 'type'), true)) {
                $cursor = max($cursor, (int) $run->last_sequence) + 1;
                $copy = $this->messages->format($run->locale, $type);
                $events[] = [
                    'run_id' => $run->run_id, 'sequence' => $cursor, 'type' => $type, 'phase' => 'run',
                    ...$copy, 'progress' => null, 'can_cancel' => false,
                    'data' => ['status_snapshot' => true, 'requested_extension' => data_get($run->budget_json, 'requested_extension')],
                    'created_at' => $run->updated_at?->toIso8601String(),
                ];
            }
        }

        return ['events' => $events, 'next_sequence' => $cursor, 'has_more' => $hasMore, 'status' => $run->status];
    }
}

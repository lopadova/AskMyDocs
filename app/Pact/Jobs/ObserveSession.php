<?php

declare(strict_types=1);

namespace App\Pact\Jobs;

use App\Pact\SessionContinuation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ObserveSession implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $tries = 180;
    public function __construct(public readonly int $handoffId) { $this->onQueue((string) config('agent.queue', 'agent')); $this->afterCommit(); }
    public function handle(SessionContinuation $continuation): void { if ($continuation->observe($this->handoffId)) $this->release(2); }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\ApiTools;

use App\ApiTools\Jobs\SweepApiTools;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class ApiToolSchedulerTest extends TestCase
{
    public function test_disabled_tools_skip_the_scheduled_dispatch_before_accessing_the_queue(): void
    {
        config(['api-tools.enabled' => false]);
        Queue::fake();

        $event = $this->sweepEvent();
        $passes = $event->filtersPass($this->app);
        if ($passes) {
            $event->run($this->app);
        }

        $this->assertFalse($passes);
        Queue::assertNothingPushed();
    }

    public function test_enabled_tools_still_dispatch_the_periodic_sweep(): void
    {
        config(['api-tools.enabled' => true]);
        Queue::fake();

        $event = $this->sweepEvent();
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->filtersPass($this->app));
        $event->run($this->app);

        Queue::assertPushedOn(config('api-tools.queue'), SweepApiTools::class);
    }

    private function sweepEvent(): CallbackEvent
    {
        return collect($this->app->make(Schedule::class)->events())
            ->sole(fn ($event): bool => $event->description === SweepApiTools::class);
    }
}

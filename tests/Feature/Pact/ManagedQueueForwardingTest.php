<?php

declare(strict_types=1);

namespace Tests\Feature\Pact;

use App\Console\Commands\ForwardNativePactJobsCommand;
use App\Jobs\ExecuteAgentRunJob;
use Illuminate\Contracts\Queue\Factory;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

final class ManagedQueueForwardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'pact.enabled' => true,
            'pact.role' => 'client',
            'queue.connections.pact_client' => [
                'driver' => 'database',
                'table' => 'jobs',
                'queue' => 'pact-client',
                'retry_after' => 300,
            ],
            'queue.connections.cloud' => [
                'driver' => 'cloud',
                'queues' => ['default' => [], 'pact-client' => []],
            ],
        ]);
    }

    public function test_sends_the_pending_pact_job_to_cloud_and_leaves_other_queues_alone(): void
    {
        $source = app('queue')->connection('pact_client');
        $source->push(new ExecuteAgentRunJob(1, 'pact-qa'));
        $untouched = $source->push(new ExecuteAgentRunJob(2, 'pact-qa'), '', 'default');
        $payload = DB::table('jobs')->where('queue', 'pact-client')->value('payload');
        $cloud = $this->createMock(Queue::class);
        $cloud->expects($this->once())->method('pushRaw')->with($payload, 'pact-client')->willReturn('cloud-message');
        $queues = $this->factory($source, $cloud);

        $this->assertTrue(ForwardNativePactJobsCommand::hasJobs($queues));
        $this->assertSame(0, (new ForwardNativePactJobsCommand)->handle($queues));
        $this->assertFalse(ForwardNativePactJobsCommand::hasJobs($queues));
        $this->assertDatabaseHas('jobs', ['id' => $untouched, 'queue' => 'default', 'attempts' => 0]);
    }

    public function test_rolls_back_the_reservation_if_cloud_rejects_the_publish(): void
    {
        $source = app('queue')->connection('pact_client');
        $id = $source->push(new ExecuteAgentRunJob(1, 'pact-qa'));
        $cloud = $this->createMock(Queue::class);
        $cloud->expects($this->once())->method('pushRaw')->willThrowException(new RuntimeException('Cloud unavailable'));

        $this->expectExceptionMessage('Cloud unavailable');
        try {
            (new ForwardNativePactJobsCommand)->handle($this->factory($source, $cloud));
        } finally {
            $this->assertDatabaseHas('jobs', [
                'id' => $id, 'queue' => 'pact-client', 'reserved_at' => null, 'attempts' => 0,
            ]);
        }
    }

    public function test_requires_the_named_managed_queue_to_be_provisioned(): void
    {
        config(['queue.connections.cloud.queues' => ['default']]);
        $queues = $this->createMock(Factory::class);
        $queues->expects($this->never())->method('connection');
        $this->assertSame(0, (new ForwardNativePactJobsCommand)->handle($queues));

        config(['queue.connections.cloud.queues' => ['default', 'pact-client']]);
        $this->assertTrue(ForwardNativePactJobsCommand::enabled());
    }

    private function factory(DatabaseQueue $database, Queue $cloud): Factory
    {
        $queues = $this->createStub(Factory::class);
        $queues->method('connection')->willReturnMap([
            ['pact_client', $database],
            ['cloud', $cloud],
        ]);

        return $queues;
    }
}

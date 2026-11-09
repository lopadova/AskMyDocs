<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Connectors\SerializedConnectorSyncJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Queue;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Tests\TestCase;

final class ImapQueueAdmissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_requests_coalesce_per_installation_and_tenant_and_expiry_releases_admission(): void
    {
        Queue::fake();
        $installation = $this->installation();
        for ($i = 0; $i < 10; $i++) {
            SerializedConnectorSyncJob::dispatchFor($installation);
        }
        $otherTenant = clone $installation;
        $otherTenant->tenant_id = 'another-tenant';
        SerializedConnectorSyncJob::dispatchFor($otherTenant);
        SerializedConnectorSyncJob::dispatchFor($this->installation('Second'));
        Queue::assertPushed(SerializedConnectorSyncJob::class, 3);

        $pending = Queue::pushed(SerializedConnectorSyncJob::class)->first();
        $staleCopy = clone $pending;
        $pending->failed(new MaxAttemptsExceededException('Expired retry window'));
        SerializedConnectorSyncJob::dispatchFor($installation);
        $staleCopy->failed(new MaxAttemptsExceededException('Late expiry notification'));
        SerializedConnectorSyncJob::dispatchFor($installation);
        Queue::assertPushed(SerializedConnectorSyncJob::class, 4);
    }

    public function test_a_deferred_payload_expiring_does_not_disable_a_healthy_imap_account(): void
    {
        $installation = $this->installation();
        $before = $installation->updated_at;
        (new SerializedConnectorSyncJob($installation->id, 'autry'))->failed(new MaxAttemptsExceededException('Expired retry window'));
        $this->assertSame('active', $installation->fresh()->status);
        $this->assertNull($installation->fresh()->error_json);
        $this->assertTrue($installation->fresh()->updated_at->eq($before));
    }

    public function test_hard_timeouts_still_disable_the_exact_account_and_preserve_other_tenants(): void
    {
        $installation = $this->installation();
        (new SerializedConnectorSyncJob($installation->id, 'foreign'))->failed(new TimeoutExceededException('Timeout'));
        $this->assertSame('active', $installation->fresh()->status);
        (new SerializedConnectorSyncJob($installation->id, 'autry'))->failed(new TimeoutExceededException('Timeout'));
        $this->assertSame('errored', $installation->fresh()->status);
        $this->assertSame(TimeoutExceededException::class, $installation->fresh()->error_json['class']);
    }

    public function test_dispatch_preserves_the_existing_fallback_for_an_unavailable_lock_store(): void
    {
        config(['cache.default' => 'not-configured']);
        Queue::fake();
        SerializedConnectorSyncJob::dispatchFor($this->installation());
        Queue::assertPushed(SerializedConnectorSyncJob::class, 1);
    }

    public function test_processing_releases_admission_for_the_next_batch(): void
    {
        Queue::fake();
        $installation = $this->installation();
        SerializedConnectorSyncJob::dispatchFor($installation);
        $job = Queue::pushed(SerializedConnectorSyncJob::class)->first();
        $connector = \Mockery::mock(\Padosoft\AskMyDocsConnectorBase\ConnectorInterface::class);
        $connector->shouldReceive('syncIncremental')->once()->andReturn(\Padosoft\AskMyDocsConnectorBase\SyncResult::empty());
        $registry = \Mockery::mock(\Padosoft\AskMyDocsConnectorBase\ConnectorRegistry::class);
        $registry->shouldReceive('get')->with('imap')->andReturn($connector);
        $job->handle($registry, app(\Padosoft\AskMyDocsConnectorBase\Support\TenantContext::class));
        SerializedConnectorSyncJob::dispatchFor($installation);
        SerializedConnectorSyncJob::dispatchFor($installation);
        Queue::assertPushed(SerializedConnectorSyncJob::class, 2);
    }

    private function installation(string $label = 'Autry'): ConnectorInstallation
    {
        return ConnectorInstallation::create(['tenant_id' => 'autry', 'connector_name' => 'imap', 'label' => $label,
            'status' => 'active', 'config_json' => [], 'created_by' => 1]);
    }
}

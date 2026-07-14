<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Connectors\Imap\MailboxBusyException;
use App\Connectors\SerializedConnectorSyncJob;
use Illuminate\Contracts\Queue\Job as QueueJobContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Padosoft\AskMyDocsConnectorBase\ConnectorInterface;
use Padosoft\AskMyDocsConnectorBase\ConnectorRegistry;
use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorApiException;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;
use Tests\TestCase;

/**
 * A {@see MailboxBusyException} during a sync is TRANSIENT (another connection to the
 * account is live right now, or a stale Layer-1 lock from a killed worker) — it must
 * NOT leave the installation ERRORED with a red "Sync failed". The host job undoes the
 * vendor parent's ERRORED write and re-queues, so auto-sync keeps going. A REAL error
 * (non-busy) must still hard-fail — the recovery must not swallow it.
 */
final class SerializedSyncJobMailboxBusyTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_mailbox_busy_keeps_the_install_active_and_requeues(): void
    {
        config()->set('connectors.imap.mailbox_lock.requeue_after_seconds', 7);

        $installation = $this->imapInstallation();

        // The connector throws MailboxBusyException → the vendor parent flips the row
        // to ERRORED + writes error_json(class=MailboxBusyException) then re-throws.
        $connector = Mockery::mock(ConnectorInterface::class);
        $connector->shouldReceive('syncIncremental')
            ->once()
            ->andThrow(new MailboxBusyException('Mailbox busy: another connection to this account is already in progress.'));

        $registry = Mockery::mock(ConnectorRegistry::class);
        $registry->shouldReceive('get')->with('imap')->andReturn($connector);

        // The re-queue is a release, not a failure (no JobFailed → no red FAILED run).
        $queueJob = Mockery::mock(QueueJobContract::class);
        $queueJob->shouldReceive('release')->once()->with(7);

        $job = new SerializedConnectorSyncJob($installation->id, 'default');
        $job->setJob($queueJob);

        // Must NOT propagate — a busy is absorbed, not thrown.
        $job->handle($registry, app(TenantContext::class));

        $installation->refresh();
        $this->assertSame(
            ConnectorInstallation::STATUS_ACTIVE,
            $installation->status,
            'a transient busy must not leave the install ERRORED',
        );
        $this->assertNull($installation->error_json, 'the busy error_json must be cleared on re-queue');
    }

    public function test_a_real_non_busy_error_still_hard_fails(): void
    {
        $installation = $this->imapInstallation();

        // A genuine transport/auth error is NOT a busy — it must propagate so the
        // installation stays ERRORED and the operator sees it (recovery is scoped to
        // MailboxBusyException only; it must never swallow a real failure).
        $connector = Mockery::mock(ConnectorInterface::class);
        $connector->shouldReceive('syncIncremental')
            ->once()
            ->andThrow(new ConnectorApiException('IMAP connect failed: host unreachable'));

        $registry = Mockery::mock(ConnectorRegistry::class);
        $registry->shouldReceive('get')->with('imap')->andReturn($connector);

        // A real error must NOT re-queue via the busy path.
        $queueJob = Mockery::mock(QueueJobContract::class);
        $queueJob->shouldNotReceive('release');

        $job = new SerializedConnectorSyncJob($installation->id, 'default');
        $job->setJob($queueJob);

        try {
            $job->handle($registry, app(TenantContext::class));
            $this->fail('a non-busy error must propagate so the job fails');
        } catch (ConnectorApiException $e) {
            $this->assertStringContainsString('host unreachable', $e->getMessage());
        }

        $installation->refresh();
        $this->assertSame(
            ConnectorInstallation::STATUS_ERRORED,
            $installation->status,
            'a real error must leave the install ERRORED, not be recovered',
        );
    }

    private function imapInstallation(): ConnectorInstallation
    {
        return ConnectorInstallation::create([
            'tenant_id' => 'default',
            'connector_name' => 'imap',
            'label' => 'busy-test',
            'config_json' => ['connection' => ['host' => 'imap.example.test', 'port' => 993, 'username' => 'u@example.test']],
            'status' => ConnectorInstallation::STATUS_ACTIVE,
            'created_by' => 1,
        ]);
    }
}

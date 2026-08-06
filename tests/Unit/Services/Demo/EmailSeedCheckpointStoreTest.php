<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Demo;

use App\Services\Demo\EmailSeedCheckpoint;
use App\Services\Demo\EmailSeedCheckpointStore;
use App\Services\Demo\EmailSeedPurgeIntent;
use App\Services\Demo\MailboxTarget;
use App\Services\Demo\PreparedEmailMessage;
use DateTimeImmutable;
use RuntimeException;
use Tests\TestCase;

final class EmailSeedCheckpointStoreTest extends TestCase
{
    public function test_round_trips_atomic_progress_and_rejects_a_changed_manifest(): void
    {
        $directory = sys_get_temp_dir().'/askmydocs-email-checkpoint-'.bin2hex(random_bytes(8));
        $store = new EmailSeedCheckpointStore($directory);
        $target = $this->target();
        $checkpoint = $store->load($target, 'large-v1', str_repeat('a', 64));

        $checkpoint = $checkpoint->advance($this->message(sequence: 1), false);
        $checkpoint = $checkpoint->advance($this->message(sequence: 2), true);
        $store->save($target, $checkpoint);

        $loaded = $store->load($target, 'large-v1', str_repeat('a', 64));
        $this->assertSame(2, $loaded->lastSequence);
        $this->assertSame(1, $loaded->appended);
        $this->assertSame(1, $loaded->alreadyPresent);
        $this->assertCount(1, glob($directory.'/*.json') ?: []);
        $this->assertSame([], glob($directory.'/*.tmp.*') ?: []);

        try {
            $store->load($target, 'large-v1', str_repeat('b', 64));
            $this->fail('A changed manifest checksum must be rejected.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('manifest', strtolower($e->getMessage()));
        }

        $store->clear($target, 'large-v1');
        $this->assertSame([], glob($directory.'/*.json') ?: []);
        $this->assertTrue(rmdir($directory));
    }

    public function test_clear_all_removes_every_version_only_for_the_selected_physical_mailbox(): void
    {
        $directory = sys_get_temp_dir().'/askmydocs-email-checkpoint-'.bin2hex(random_bytes(8));
        $store = new EmailSeedCheckpointStore($directory);
        $target = $this->target();
        $otherTarget = $this->target(
            mailboxKey: 'rotta-logistics-2',
            email: 'other@example.com',
            folder: 'rotta-logistics-2',
        );

        foreach (['gold-v1', 'large-v1'] as $datasetVersion) {
            $store->save(
                $target,
                new EmailSeedCheckpoint(
                    $target->mailboxKey,
                    $datasetVersion,
                    hash('sha256', $datasetVersion),
                ),
            );
        }
        $store->save(
            $otherTarget,
            new EmailSeedCheckpoint(
                $otherTarget->mailboxKey,
                'large-v1',
                hash('sha256', 'other-large-v1'),
            ),
        );

        $this->assertSame(2, $store->clearAll($target));
        $this->assertFalse($store->exists($target, 'gold-v1'));
        $this->assertFalse($store->exists($target, 'large-v1'));
        $this->assertTrue($store->exists($otherTarget, 'large-v1'));
        $this->assertSame(0, $store->clearAll($target));

        $store->clear($otherTarget, 'large-v1');
        $this->assertTrue(rmdir($directory));
    }

    public function test_clear_all_rejects_an_oversized_checkpoint_with_a_bounded_read(): void
    {
        $directory = sys_get_temp_dir().'/askmydocs-email-checkpoint-'.bin2hex(random_bytes(8));
        $store = new EmailSeedCheckpointStore($directory);
        $target = $this->target();
        $store->save(
            $target,
            new EmailSeedCheckpoint(
                $target->mailboxKey,
                'large-v1',
                str_repeat('a', 64),
            ),
        );

        $checkpointPaths = glob($directory.'/*.json');
        $this->assertIsArray($checkpointPaths);
        $this->assertCount(1, $checkpointPaths);
        $oversized = str_repeat('x', 65_537);
        $this->assertSame(
            strlen($oversized),
            file_put_contents($checkpointPaths[0], $oversized, LOCK_EX),
        );

        try {
            $store->clearAll($target);
            $this->fail('An oversized checkpoint must fail loudly.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('troppo grande', $e->getMessage());
        } finally {
            $this->assertTrue(unlink($checkpointPaths[0]));
            $this->assertTrue(rmdir($directory));
        }
    }

    public function test_purge_intent_is_durable_and_isolated_by_physical_mailbox(): void
    {
        $directory = sys_get_temp_dir().'/askmydocs-email-checkpoint-'.bin2hex(random_bytes(8));
        $store = new EmailSeedCheckpointStore($directory);
        $target = $this->target();
        $otherTarget = $this->target(
            mailboxKey: 'rotta-logistics-2',
            email: 'other@example.com',
            folder: 'rotta-logistics-2',
        );
        $intent = EmailSeedPurgeIntent::dataset(
            mailboxKey: $target->mailboxKey,
            datasetVersion: 'large-v1',
            manifestChecksum: str_repeat('a', 64),
        );

        $store->beginPurge($target, $intent);

        $loaded = $store->pendingPurge($target);
        $this->assertNotNull($loaded);
        $this->assertSame(EmailSeedPurgeIntent::PURGE_DATASET, $loaded->operation);
        $this->assertSame('large-v1', $loaded->datasetVersion);
        $this->assertSame(str_repeat('a', 64), $loaded->manifestChecksum);
        $this->assertNull($store->pendingPurge($otherTarget));
        $this->assertCount(1, glob($directory.'/*.purge-intent.json') ?: []);
        $this->assertSame([], glob($directory.'/*.tmp') ?: []);

        $store->completePurge($target);
        $this->assertNull($store->pendingPurge($target));
        $this->assertSame([], glob($directory.'/*') ?: []);
        $this->assertTrue(rmdir($directory));
    }

    private function target(
        string $mailboxKey = 'rotta-logistics-1',
        string $email = 'fixture@example.com',
        string $folder = 'rotta-logistics-1',
    ): MailboxTarget
    {
        return new MailboxTarget(
            mailboxKey: $mailboxKey,
            projectKey: 'rotta-logistics',
            companyName: 'Rotta Sicura Logistics',
            email: $email,
            host: 'imap.example.com',
            port: 993,
            encryption: 'ssl',
            validateCert: true,
            secret: 'secret',
            folder: $folder,
        );
    }

    private function message(int $sequence): PreparedEmailMessage
    {
        $fixtureId = hash('sha256', (string) $sequence);

        return new PreparedEmailMessage(
            raw: 'raw',
            internalDate: new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            fixtureId: $fixtureId,
            messageId: '<large-v1.'.$fixtureId.'@fixtures.askmydocs.invalid>',
            sequence: $sequence,
            subject: 'Subject',
            datasetVersion: 'large-v1',
        );
    }
}

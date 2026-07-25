<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Connectors\Imap\MailboxLockKey;
use App\Services\Demo\Contracts\MailboxAppender;
use App\Services\Demo\EmailMessageBuilder;
use App\Services\Demo\EmailSeedCheckpoint;
use App\Services\Demo\EmailSeedCheckpointStore;
use App\Services\Demo\EmailSeedMailboxLock;
use App\Services\Demo\MailboxTarget;
use App\Services\Demo\PreparedEmailMessage;
use Database\Seeders\TestEmailFixtures;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Tests\Support\Demo\RecordingMailboxAppender;
use Tests\TestCase;

/**
 * Feature test del comando `mail:seed-imap`. L'IMAP è l'unico confine esterno
 * (R13): lo sostituiamo con un {@see RecordingMailboxAppender} che registra gli
 * APPEND invece di toccare un server reale. Niente DB necessario.
 *
 * Modello: 2 caselle per azienda (6 caselle totali). Pin: --all inietta tutte le
 * e-mail di tutte le caselle; --project espande alle 2 caselle dell'azienda;
 * --dry-run NON invia nulla (prova R26); password mancante fallisce (R14);
 * --purge invoca il purge della casella.
 */
final class MailSeedImapCommandTest extends TestCase
{
    /** @var list<string> */
    private array $touchedEnv = [];

    /** @var list<string> */
    private array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->touchedEnv as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
        $this->touchedEnv = [];

        $files = new Filesystem;
        foreach ($this->temporaryDirectories as $directory) {
            if (is_dir($directory)) {
                $this->assertTrue($files->deleteDirectory($directory));
            }
        }
        $this->temporaryDirectories = [];

        parent::tearDown();
    }

    private function setPassword(string $envKey, string $value): void
    {
        putenv("{$envKey}={$value}");
        $_ENV[$envKey] = $value;
        $_SERVER[$envKey] = $value;
        $this->touchedEnv[] = $envKey;
    }

    private function bindRecorder(int $purgeReturns = 0): RecordingMailboxAppender
    {
        $appender = new RecordingMailboxAppender($purgeReturns);
        $this->app->instance(MailboxAppender::class, $appender);

        return $appender;
    }

    public function test_every_mailbox_has_at_least_100_emails(): void
    {
        // Requisito: ≥100 e-mail di vario tipo per casella (guard puro sul fixture,
        // non costruisce messaggi → veloce anche con 600+ e-mail).
        foreach (TestEmailFixtures::mailboxKeys() as $mailboxKey) {
            $this->assertGreaterThanOrEqual(
                100,
                count(TestEmailFixtures::emailsForMailbox($mailboxKey)),
                "La casella {$mailboxKey} deve avere almeno 100 e-mail.",
            );
        }
    }

    public function test_single_mailbox_appends_all_its_emails(): void
    {
        $this->setPassword('CONNECTOR_TEST_GMAIL_PASSWORD', 'pw');
        $appender = $this->bindRecorder();

        $this->artisan('mail:seed-imap', ['--mailbox' => ['rotta-logistics-1']])->assertExitCode(0);

        // Conteggio derivato dal fixture (R18).
        $this->assertCount(
            count(TestEmailFixtures::emailsForMailbox('rotta-logistics-1')),
            $appender->appends,
        );
        $this->assertSame(['rotta-logistics-1'], array_values(array_unique($appender->appendedMailboxKeys())));
    }

    public function test_project_expands_to_both_company_mailboxes(): void
    {
        $this->setPassword('CONNECTOR_TEST_GMAIL_PASSWORD', 'pw');
        $appender = $this->bindRecorder();

        $this->artisan('mail:seed-imap', ['--project' => ['rotta-logistics']])->assertExitCode(0);

        $seen = array_values(array_unique($appender->appendedMailboxKeys()));
        sort($seen);
        $this->assertSame(['rotta-logistics-1', 'rotta-logistics-2'], $seen);
    }

    public function test_dry_run_appends_nothing(): void
    {
        // Nessuna password impostata: in dry-run non serve e non si tocca la rete.
        $appender = $this->bindRecorder();

        $this->artisan('mail:seed-imap', ['--mailbox' => ['rotta-logistics-1'], '--dry-run' => true])->assertExitCode(0);

        $this->assertSame([], $appender->appends, 'dry-run non deve inviare alcun messaggio');
        $this->assertSame([], $appender->purges);
    }

    public function test_dry_run_never_waits_for_the_physical_mailbox_lock(): void
    {
        config()->set('connectors.imap.serialize_connections', true);
        config()->set('connectors.imap.mailbox_lock.wait_seconds', 0);
        $appender = $this->bindRecorder();
        $mailbox = TestEmailFixtures::mailbox('rotta-logistics-1');
        $key = MailboxLockKey::forConnection([
            'host' => $mailbox['host'],
            'port' => $mailbox['port'],
            'username' => $mailbox['email'],
        ]);
        $this->assertNotNull($key);
        $held = Cache::store()->getStore()->lock($key, 60);
        $this->assertTrue($held->get());

        try {
            $this->artisan('mail:seed-imap', [
                '--mailbox' => ['rotta-logistics-1'],
                '--dry-run' => true,
            ])->assertExitCode(0);
        } finally {
            $this->assertTrue($held->release());
        }

        $this->assertSame([], $appender->appends);
        $this->assertSame([], $appender->purges);
    }

    public function test_busy_sync_lock_blocks_seed_using_the_same_physical_mailbox_key(): void
    {
        config()->set('connectors.imap.serialize_connections', true);
        config()->set('connectors.imap.mailbox_lock.wait_seconds', 0);
        $this->setPassword('CONNECTOR_TEST_GMAIL_PASSWORD', 'pw');
        $appender = $this->bindRecorder();
        $mailbox = TestEmailFixtures::mailbox('rotta-logistics-1');
        $key = MailboxLockKey::forConnection([
            'host' => $mailbox['host'],
            'port' => $mailbox['port'],
            'username' => $mailbox['email'],
        ]);
        $this->assertNotNull($key);
        $held = Cache::store()->getStore()->lock($key, 60);
        $this->assertTrue($held->get());

        try {
            $this->artisan('mail:seed-imap', [
                '--mailbox' => ['rotta-logistics-1'],
            ])->assertExitCode(1);
        } finally {
            $this->assertTrue($held->release());
        }

        $this->assertSame([], $appender->appends);
        $this->assertSame([], $appender->purges);
    }

    public function test_missing_password_fails_loudly(): void
    {
        // CONNECTOR_TEST_GMAIL_PASSWORD volutamente assente.
        $appender = $this->bindRecorder();

        $this->artisan('mail:seed-imap', ['--mailbox' => ['rotta-logistics-1']])->assertExitCode(1);

        $this->assertSame([], $appender->appends, 'senza password non deve inviare nulla');
    }

    public function test_no_mailbox_selected_fails(): void
    {
        $this->bindRecorder();

        $this->artisan('mail:seed-imap')->assertExitCode(1);
    }

    public function test_purge_runs_before_append(): void
    {
        $this->setPassword('CONNECTOR_TEST_GMAIL_PASSWORD', 'pw');
        $appender = $this->bindRecorder(purgeReturns: 2);

        $this->artisan('mail:seed-imap', [
            '--mailbox' => ['rotta-logistics-1'],
            '--purge' => true,
        ])->assertExitCode(0);

        $this->assertCount(1, $appender->purges);
        $this->assertSame('rotta-logistics-1', $appender->purges[0]['value']);
        $this->assertCount(
            count(TestEmailFixtures::emailsForMailbox('rotta-logistics-1')),
            $appender->appends,
        );

        // R16 — il purge DEVE avvenire PRIMA dell'append: un append-then-purge
        // cancellerebbe i messaggi appena iniettati (purge filtra per header).
        // La timeline condivisa lo rende osservabile.
        $this->assertSame('purge', $appender->events[0]['op'] ?? null);
        $appendIndex = null;
        foreach ($appender->events as $i => $event) {
            if ($event['op'] === 'append') {
                $appendIndex = $i;
                break;
            }
        }
        $this->assertNotNull($appendIndex, 'deve esserci un append dopo il purge');
        $this->assertGreaterThan(0, $appendIndex, 'il purge (indice 0) precede il primo append');
    }

    public function test_legacy_purge_clears_checkpoints_for_every_dataset_version(): void
    {
        $this->setPassword('CONNECTOR_TEST_GMAIL_PASSWORD', 'pw');
        $checkpointRoot = $this->temporaryDirectory('legacy-purge-checkpoints');
        $store = new EmailSeedCheckpointStore($checkpointRoot);
        $this->app->instance(EmailSeedCheckpointStore::class, $store);
        $target = $this->mailboxTarget('rotta-logistics-1');
        $this->saveCheckpoint($store, $target, 'gold-v1');
        $this->saveCheckpoint($store, $target, 'large-v1');
        $appender = new RecordingMailboxAppender(
            purgeReturns: 4,
            failAfterStored: 1,
        );
        $this->app->instance(MailboxAppender::class, $appender);

        $this->artisan('mail:seed-imap', [
            '--mailbox' => ['rotta-logistics-1'],
            '--purge' => true,
        ])->assertExitCode(1);

        // La consegna fallisce subito dopo il purge: i checkpoint devono essere
        // già spariti, altrimenti il prossimo run riprenderebbe uno stato remoto
        // che il purge globale ha appena eliminato.
        $this->assertFalse($store->exists($target, 'gold-v1'));
        $this->assertFalse($store->exists($target, 'large-v1'));
        $this->assertCount(1, $appender->purges);
        $this->assertSame(TestEmailFixtures::SEED_HEADER, $appender->purges[0]['header']);
    }

    public function test_generated_dataset_resumes_from_atomic_checkpoint_without_duplicates(): void
    {
        $this->setPassword('CONNECTOR_TEST_GMAIL_PASSWORD', 'pw');
        $datasetRoot = $this->temporaryDirectory('dataset');
        $checkpointRoot = $this->temporaryDirectory('checkpoint');

        $this->artisan('demo:generate-case-study-emails', [
            '--profile' => 'gold',
            '--seed' => '17',
            '--mailbox' => ['rotta-logistics-2'],
            '--output' => $datasetRoot,
        ])->assertExitCode(0);

        $manifests = glob($datasetRoot.'/*/manifest.json');
        $this->assertIsArray($manifests);
        $this->assertCount(1, $manifests);
        $manifest = json_decode(
            (string) file_get_contents($manifests[0]),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $datasetVersion = (string) $manifest['dataset_version'];
        $this->assertSame(126, $manifest['total_records']);

        $this->app->instance(
            EmailSeedCheckpointStore::class,
            new EmailSeedCheckpointStore($checkpointRoot),
        );

        $interrupted = new RecordingMailboxAppender(failAfterStored: 3);
        $this->app->instance(MailboxAppender::class, $interrupted);
        $arguments = [
            '--mailbox' => ['rotta-logistics-2'],
            '--dataset-version' => $datasetVersion,
            '--dataset-root' => $datasetRoot,
            '--batch-size' => '10',
            '--summary-only' => true,
        ];

        $this->artisan('mail:seed-imap', $arguments)->assertExitCode(1);
        $this->assertCount(3, $interrupted->appends);

        // Simula il caso ambiguo: la sequenza 4 ha raggiunto il server dopo
        // l'ultimo checkpoint. Il resume verifica il Message-ID e non la riappende.
        $resumed = new RecordingMailboxAppender(alreadyPresentSequences: [4]);
        $this->app->instance(MailboxAppender::class, $resumed);
        $this->artisan('mail:seed-imap', $arguments + ['--resume' => true])
            ->assertExitCode(0);

        $this->assertCount(122, $resumed->appends);
        $this->assertSame(5, $resumed->appends[0]['sequence']);
        $this->assertTrue($resumed->appends[0]['verifyBeforeAppend']);
        $this->assertStringContainsString(
            '@fixtures.askmydocs.invalid>',
            $resumed->appends[0]['raw'],
        );

        // Un secondo resume su checkpoint completo è un no-op, non una seconda
        // consegna della stessa dataset.
        $complete = new RecordingMailboxAppender;
        $this->app->instance(MailboxAppender::class, $complete);
        $this->artisan('mail:seed-imap', $arguments + ['--resume' => true])
            ->assertExitCode(0);
        $this->assertSame([], $complete->appends);
    }

    public function test_append_ack_after_lock_ttl_does_not_advance_checkpoint_and_resumes_without_duplicate(): void
    {
        config()->set('connectors.imap.serialize_connections', true);
        config()->set('connectors.imap.mailbox_lock.wait_seconds', 0);
        config()->set('connectors.imap.mailbox_lock.seed_ttl_seconds', 5);
        config()->set('connectors.imap.mailbox_lock.seed_safety_margin_seconds', 2);
        $this->setPassword('CONNECTOR_TEST_GMAIL_PASSWORD', 'pw');
        $datasetRoot = $this->temporaryDirectory('lease-dataset');
        $checkpointRoot = $this->temporaryDirectory('lease-checkpoints');
        $datasetVersion = $this->generateDataset(
            $datasetRoot,
            ['rotta-logistics-1'],
            seed: 34,
        );
        $store = new EmailSeedCheckpointStore($checkpointRoot);
        $this->app->instance(EmailSeedCheckpointStore::class, $store);
        $target = $this->mailboxTarget('rotta-logistics-1');

        $clockSeconds = 1_000.0;
        $this->app->instance(
            EmailSeedMailboxLock::class,
            new EmailSeedMailboxLock(
                static function () use (&$clockSeconds): float {
                    return $clockSeconds;
                },
            ),
        );

        // Il primo ACK arriva entro la lease e viene checkpointato. Il secondo
        // APPEND parte mentre il processo possiede ancora il lock, ma il server
        // lo conferma oltre il TTL: resta ambiguamente presente sul server, non
        // avanza il checkpoint e il terzo APPEND non deve mai iniziare.
        $interrupted = new RecordingMailboxAppender(
            afterMessageStored: static function (
                PreparedEmailMessage $message,
            ) use (&$clockSeconds): void {
                $clockSeconds += $message->sequence === 1 ? 1.0 : 6.0;
            },
        );
        $this->app->instance(MailboxAppender::class, $interrupted);
        $arguments = [
            '--mailbox' => ['rotta-logistics-1'],
            '--dataset-version' => $datasetVersion,
            '--dataset-root' => $datasetRoot,
            '--batch-size' => '1',
            '--summary-only' => true,
        ];

        $this->artisan('mail:seed-imap', $arguments)
            ->expectsOutputToContain('TTL superato prima del rinnovo')
            ->assertExitCode(1);

        $this->assertCount(2, $interrupted->appends);
        $this->assertSame([1, 2], array_column($interrupted->appends, 'sequence'));
        $manifestPath = $datasetRoot.'/'.$datasetVersion.'/manifest.json';
        $manifestChecksum = hash_file('sha256', $manifestPath);
        $this->assertIsString($manifestChecksum);
        $checkpoint = $store->load($target, $datasetVersion, $manifestChecksum);
        $this->assertSame(1, $checkpoint->lastSequence);
        $this->assertSame(1, $checkpoint->appended);

        // Sequence 2 is the ambiguous server ACK. Resume searches its stable
        // Message-ID, treats it as already present, then starts APPEND at 3.
        $resumed = new RecordingMailboxAppender(alreadyPresentSequences: [2]);
        $this->app->instance(MailboxAppender::class, $resumed);
        $this->artisan('mail:seed-imap', $arguments + ['--resume' => true])
            ->assertExitCode(0);

        $this->assertNotSame([], $resumed->appends);
        $this->assertSame(3, $resumed->appends[0]['sequence']);
        $this->assertFalse(
            $resumed->appends[0]['verifyBeforeAppend'],
            'sequence 2 was verified and skipped; sequence 3 starts normal APPENDs',
        );
        $completed = $store->load($target, $datasetVersion, $manifestChecksum);
        $this->assertSame(2 + count($resumed->appends), $completed->lastSequence);
        $this->assertSame(1, $completed->alreadyPresent);
    }

    public function test_generated_dataset_prevalidates_every_mailbox_before_any_purge_or_append(): void
    {
        $this->setPassword('CONNECTOR_TEST_GMAIL_PASSWORD', 'pw');
        $datasetRoot = $this->temporaryDirectory('prevalidation-dataset');
        $datasetVersion = $this->generateDataset(
            $datasetRoot,
            ['rotta-logistics-1'],
            seed: 31,
        );
        $appender = $this->bindRecorder(purgeReturns: 4);

        $this->artisan('mail:seed-imap', [
            '--mailbox' => ['rotta-logistics-1', 'rotta-logistics-2'],
            '--dataset-version' => $datasetVersion,
            '--dataset-root' => $datasetRoot,
            '--purge-dataset' => true,
            '--summary-only' => true,
        ])->assertExitCode(1);

        $this->assertSame(
            [],
            $appender->events,
            'a missing second mailbox must fail before mutating the valid first mailbox',
        );
        $this->assertSame([], $appender->purges);
        $this->assertSame([], $appender->appends);
    }

    public function test_purge_all_seeded_clears_stale_versions_and_persists_the_new_checkpoint(): void
    {
        $this->setPassword('CONNECTOR_TEST_GMAIL_PASSWORD', 'pw');
        $datasetRoot = $this->temporaryDirectory('purge-all-dataset');
        $checkpointRoot = $this->temporaryDirectory('purge-all-checkpoints');
        $datasetVersion = $this->generateDataset(
            $datasetRoot,
            ['rotta-logistics-1'],
            seed: 33,
        );
        $store = new EmailSeedCheckpointStore($checkpointRoot);
        $this->app->instance(EmailSeedCheckpointStore::class, $store);
        $target = $this->mailboxTarget('rotta-logistics-1');
        $this->saveCheckpoint($store, $target, 'obsolete-v1');
        $this->saveCheckpoint($store, $target, 'obsolete-v2');
        $appender = $this->bindRecorder(purgeReturns: 9);

        $this->artisan('mail:seed-imap', [
            '--mailbox' => ['rotta-logistics-1'],
            '--dataset-version' => $datasetVersion,
            '--dataset-root' => $datasetRoot,
            '--purge-all-seeded' => true,
            '--summary-only' => true,
        ])->assertExitCode(0);

        $this->assertFalse($store->exists($target, 'obsolete-v1'));
        $this->assertFalse($store->exists($target, 'obsolete-v2'));
        $this->assertTrue(
            $store->exists($target, $datasetVersion),
            'il checkpoint corrente deve essere ricreato dopo il purge globale',
        );
        $this->assertCount(1, $appender->purges);
        $this->assertSame(TestEmailFixtures::SEED_HEADER, $appender->purges[0]['header']);
        $this->assertNotSame([], $appender->appends);
    }

    public function test_plain_resume_recovers_a_purge_that_crashed_before_checkpoint_clear(): void
    {
        $this->setPassword('CONNECTOR_TEST_GMAIL_PASSWORD', 'pw');
        $datasetRoot = $this->temporaryDirectory('purge-intent-dataset');
        $checkpointRoot = $this->temporaryDirectory('purge-intent-checkpoints');
        $datasetVersion = $this->generateDataset(
            $datasetRoot,
            ['rotta-logistics-1'],
            seed: 35,
        );
        $store = new EmailSeedCheckpointStore($checkpointRoot);
        $this->app->instance(EmailSeedCheckpointStore::class, $store);
        $target = $this->mailboxTarget('rotta-logistics-1');
        $arguments = [
            '--mailbox' => ['rotta-logistics-1'],
            '--dataset-version' => $datasetVersion,
            '--dataset-root' => $datasetRoot,
            '--batch-size' => '10',
            '--summary-only' => true,
        ];

        // Establish the dangerous starting state: a complete checkpoint that
        // would make a plain resume return immediately.
        $initial = $this->bindRecorder();
        $this->artisan('mail:seed-imap', $arguments)->assertExitCode(0);
        $expected = count($initial->appends);
        $this->assertGreaterThan(0, $expected);

        // The remote server accepted the destructive purge, then the process
        // died before the old complete checkpoint could be cleared.
        $interrupted = new RecordingMailboxAppender(
            purgeReturns: $expected,
            afterPurge: static function (): void {
                throw new \RuntimeException('Injected crash after remote purge.');
            },
        );
        $this->app->instance(MailboxAppender::class, $interrupted);
        $this->artisan('mail:seed-imap', $arguments + ['--purge-dataset' => true])
            ->assertExitCode(1);

        $this->assertCount(1, $interrupted->purges);
        $this->assertNotNull($store->pendingPurge($target));
        $manifestChecksum = hash_file(
            'sha256',
            $datasetRoot.'/'.$datasetVersion.'/manifest.json',
        );
        $this->assertIsString($manifestChecksum);
        $stale = $store->load($target, $datasetVersion, $manifestChecksum);
        $this->assertSame($expected, $stale->lastSequence);

        // No purge flag is repeated. Resume must discover the durable intent,
        // replay the same idempotent purge, clear the stale checkpoint and
        // deliver the whole dataset instead of reporting a false no-op.
        $resumed = new RecordingMailboxAppender;
        $this->app->instance(MailboxAppender::class, $resumed);
        $this->artisan('mail:seed-imap', $arguments + ['--resume' => true])
            ->assertExitCode(0);

        $this->assertCount(1, $resumed->purges);
        $this->assertSame(
            EmailMessageBuilder::DATASET_VERSION_HEADER,
            $resumed->purges[0]['header'],
        );
        $this->assertSame($datasetVersion, $resumed->purges[0]['value']);
        $this->assertCount($expected, $resumed->appends);
        $this->assertSame(1, $resumed->appends[0]['sequence']);
        $this->assertNull($store->pendingPurge($target));
        $completed = $store->load($target, $datasetVersion, $manifestChecksum);
        $this->assertSame($expected, $completed->lastSequence);
    }

    public function test_purge_only_removes_the_selected_dataset_without_reappending(): void
    {
        $this->setPassword('CONNECTOR_TEST_GMAIL_PASSWORD', 'pw');
        $datasetRoot = $this->temporaryDirectory('purge-only-dataset');
        $datasetVersion = $this->generateDataset(
            $datasetRoot,
            ['rotta-logistics-1'],
            seed: 32,
        );
        $appender = $this->bindRecorder(purgeReturns: 7);

        $this->artisan('mail:seed-imap', [
            '--mailbox' => ['rotta-logistics-1'],
            '--dataset-version' => $datasetVersion,
            '--dataset-root' => $datasetRoot,
            '--purge-dataset' => true,
            '--purge-only' => true,
            '--summary-only' => true,
        ])->assertExitCode(0);

        $this->assertSame([], $appender->appends);
        $this->assertCount(1, $appender->purges);
        $this->assertSame(EmailMessageBuilder::DATASET_VERSION_HEADER, $appender->purges[0]['header']);
        $this->assertSame($datasetVersion, $appender->purges[0]['value']);
        $this->assertSame(
            [['op' => 'purge', 'mailbox' => 'rotta-logistics-1']],
            $appender->events,
        );
    }

    private function temporaryDirectory(string $label): string
    {
        $directory = sys_get_temp_dir()
            ."/askmydocs-email-seed-{$label}-"
            .bin2hex(random_bytes(8));
        $this->temporaryDirectories[] = $directory;

        return $directory;
    }

    private function mailboxTarget(string $mailboxKey): MailboxTarget
    {
        $mailbox = TestEmailFixtures::mailbox($mailboxKey);
        $config = TestEmailFixtures::configJson($mailboxKey);
        $connection = (array) ($config['connection'] ?? []);
        $folders = (array) ($config['folders']['include'] ?? ['INBOX']);

        return new MailboxTarget(
            mailboxKey: $mailboxKey,
            projectKey: (string) $mailbox['project_key'],
            companyName: (string) $mailbox['company_name'],
            email: (string) $mailbox['email'],
            host: (string) ($connection['host'] ?? 'imap.gmail.com'),
            port: (int) ($connection['port'] ?? 993),
            encryption: (string) ($connection['encryption'] ?? 'ssl'),
            validateCert: (bool) ($connection['validate_cert'] ?? true),
            secret: 'pw',
            folder: (string) ($folders[0] ?? 'INBOX'),
        );
    }

    private function saveCheckpoint(
        EmailSeedCheckpointStore $store,
        MailboxTarget $target,
        string $datasetVersion,
    ): void {
        $store->save(
            $target,
            new EmailSeedCheckpoint(
                mailboxKey: $target->mailboxKey,
                datasetVersion: $datasetVersion,
                manifestChecksum: hash('sha256', $datasetVersion),
            ),
        );
    }

    /**
     * @param  list<string>  $mailboxes
     */
    private function generateDataset(
        string $root,
        array $mailboxes,
        int $seed,
    ): string {
        $this->artisan('demo:generate-case-study-emails', [
            '--profile' => 'gold',
            '--seed' => (string) $seed,
            '--mailbox' => $mailboxes,
            '--output' => $root,
        ])->assertExitCode(0);

        $manifests = glob($root.'/*/manifest.json');
        $this->assertIsArray($manifests);
        $this->assertCount(1, $manifests);
        $manifestContents = file_get_contents($manifests[0]);
        $this->assertNotFalse($manifestContents);
        $manifest = json_decode(
            $manifestContents,
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $this->assertIsArray($manifest);

        return (string) $manifest['dataset_version'];
    }
}

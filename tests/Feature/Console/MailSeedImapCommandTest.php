<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\Demo\Contracts\MailboxAppender;
use Database\Seeders\TestEmailFixtures;
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

    protected function tearDown(): void
    {
        foreach ($this->touchedEnv as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
        $this->touchedEnv = [];

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
}

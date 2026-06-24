<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\Demo\Contracts\MailboxAppender;
use Tests\Support\Demo\RecordingMailboxAppender;
use Tests\TestCase;

/**
 * Feature test del comando `mail:seed-imap`. L'IMAP è l'unico confine esterno
 * (R13): lo sostituiamo con un {@see RecordingMailboxAppender} che registra gli
 * APPEND invece di toccare un server reale. Niente DB necessario.
 *
 * Pin: --all inietta tutte le e-mail; --dry-run NON invia nulla (prova R26);
 * password mancante fallisce rumorosamente (R14); --purge invoca il purge.
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

    public function test_all_companies_get_their_emails_appended(): void
    {
        $this->setPassword('CONNECTOR_TEST_ROTTA_PASSWORD', 'pw-rotta');
        $this->setPassword('CONNECTOR_TEST_PROMETEO_PASSWORD', 'pw-prometeo');
        $this->setPassword('CONNECTOR_TEST_PASSOLIBERO_PASSWORD', 'pw-passolibero');
        $appender = $this->bindRecorder();

        $this->artisan('mail:seed-imap', ['--all' => true])->assertExitCode(0);

        // 3 aziende × 3 e-mail = 9 APPEND, con 3 per ogni project_key.
        $this->assertCount(9, $appender->appends);
        $counts = array_count_values($appender->appendedProjectKeys());
        $this->assertSame(3, $counts['rotta-logistics'] ?? 0);
        $this->assertSame(3, $counts['prometeo-antincendio'] ?? 0);
        $this->assertSame(3, $counts['passolibero-calzature'] ?? 0);
    }

    public function test_dry_run_appends_nothing(): void
    {
        // Nessuna password impostata: in dry-run non serve e non si tocca la rete.
        $appender = $this->bindRecorder();

        $this->artisan('mail:seed-imap', ['--all' => true, '--dry-run' => true])->assertExitCode(0);

        $this->assertSame([], $appender->appends, 'dry-run non deve inviare alcun messaggio');
        $this->assertSame([], $appender->purges);
    }

    public function test_missing_password_fails_loudly(): void
    {
        // CONNECTOR_TEST_ROTTA_PASSWORD volutamente assente.
        $appender = $this->bindRecorder();

        $this->artisan('mail:seed-imap', ['--project' => ['rotta-logistics']])->assertExitCode(1);

        $this->assertSame([], $appender->appends, 'senza password non deve inviare nulla');
    }

    public function test_no_company_selected_fails(): void
    {
        $this->bindRecorder();

        $this->artisan('mail:seed-imap')->assertExitCode(1);
    }

    public function test_purge_runs_before_append(): void
    {
        $this->setPassword('CONNECTOR_TEST_ROTTA_PASSWORD', 'pw-rotta');
        $appender = $this->bindRecorder(purgeReturns: 2);

        $this->artisan('mail:seed-imap', [
            '--project' => ['rotta-logistics'],
            '--purge' => true,
        ])->assertExitCode(0);

        $this->assertCount(1, $appender->purges);
        $this->assertSame('rotta-logistics', $appender->purges[0]['value']);
        $this->assertCount(3, $appender->appends);
    }
}

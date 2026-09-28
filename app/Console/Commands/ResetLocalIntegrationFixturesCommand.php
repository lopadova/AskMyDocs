<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AppSetting;
use App\Services\Admin\AppSettingsResolver;
use App\Services\Dev\LocalIntegrationFixtureEnvironment;
use App\Services\Dev\LocalIntegrationFixtureLifecycle;
use App\Support\TenantContext;
use Database\Seeders\CaseStudyUsersSeeder;
use Database\Seeders\LocalIntegrationConnectorsSeeder;
use Database\Seeders\LocalIntegrationRetrievalProfilesSeeder;
use Illuminate\Console\Command;
use Padosoft\AiActCompliance\MultiTenancy\Models\Tenant;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnection;
use RuntimeException;
use Symfony\Component\Console\Helper\ProgressBar;

/**
 * Rebuilds a self-contained local integration scenario for the three case
 * studies. It never invokes migrate:fresh and never resets a tenant outside
 * the fixed allowlist.
 */
final class ResetLocalIntegrationFixturesCommand extends Command
{
    protected $signature = 'dev:reset-local-integration-fixtures
        {--without-email : Do not mutate the dedicated Gmail fixture mailbox or install/sync IMAP}
        {--email-profile=gold : Generated fixture email profile: gold or demo}
        {--resume-email : Reuse verified Gmail delivery checkpoints instead of purging the fixture dataset again}
        {--skip-smoke : Skip the final local MCP read-only smoke calls}';

    protected $description = 'Reset only the three local case-study tenants, then configure deterministic documents, email, API and MCP fixtures.';

    public function handle(
        LocalIntegrationFixtureEnvironment $environment,
        LocalIntegrationFixtureLifecycle $lifecycle,
        AppSettingsResolver $settings,
        TenantContext $tenants,
    ): int {
        $progress = null;

        try {
            $environment->assertLocal();
            $profile = $this->emailProfile();
            $withEmail = ! (bool) $this->option('without-email');
            $resumeEmail = $withEmail && (bool) $this->option('resume-email');

            $this->components->info('Preparazione dell’ambiente di integrazione locale');
            $this->line('  Tenant: Rotta Logistics, Prometeo Antincendio, PassoLibero Calzature.');
            $this->line(sprintf(
                '  E-mail: %s%s. Documenti, API e MCP: inclusi.',
                $withEmail ? "profilo {$profile}" : 'saltate (--without-email)',
                $resumeEmail ? ' (ripresa dai checkpoint)' : '',
            ));
            $this->newLine();

            $environment->enable(app()->environmentFilePath());
            // A previous local run may have cached configuration before the
            // narrow fixture gate existed. Clear only that derived cache so
            // Herd's next request reads the persisted local flag.
            $this->callChecked('config:clear', []);
            $this->configureCurrentProcess();
            $progress = $this->stageProgress();

            $this->components->info('1/6 — Riavvio dei mock API e MCP locali');
            $this->line('  Arresto le eventuali istanze precedenti e avvio i due servizi Node su loopback.');
            $progress->setMessage('Riavvio mock API e MCP');
            $progress->display();
            $lifecycle->restart();
            $progress->advance();
            $this->line('  ✓ Mock API e MCP disponibili.');

            $this->components->info('2/6 — Reset dei soli tenant case-study');
            $this->line('  Rimuovo solo i dati delle tre aziende di prova; gli altri tenant non vengono toccati.');
            $progress->setMessage('Reset dei tenant case-study');
            $progress->display();
            foreach (CaseStudyUsersSeeder::companyKeys() as $tenantId) {
                if (Tenant::query()->where('slug', $tenantId)->exists()) {
                    $this->callChecked('tenant:reset', ['tenant' => $tenantId, '--force' => true], $progress);
                } else {
                    $this->line("  [{$tenantId}] non esiste ancora: sarà creato dal seeder.");
                }
            }
            $progress->advance();
            $this->line('  ✓ Reset dei tenant case-study completato.');

            $this->components->info('3/6 — Aziende, utenti e documenti');
            $this->line('  Creo utenti e ruoli, ingerisco i documenti e sincronizzo le e-mail previste dal profilo.');
            $progress->setMessage('Aziende, documenti ed e-mail');
            $progress->display();
            $initArguments = [
                '--profile' => $profile,
                // A retry after a transient IMAP failure preserves the
                // operation's filesystem checkpoint and never duplicates an
                // already confirmed mailbox message.
                '--generate-email-dataset' => $withEmail && ! $resumeEmail,
                '--resume' => $resumeEmail,
                '--ingest-emails' => $withEmail,
                '--local-fixture-email-reset' => $withEmail && ! $resumeEmail,
                '--skip-emails' => (bool) $this->option('without-email'),
                '--email-actor' => 'local-case-study-fixtures',
            ];
            $this->callChecked('demo:init-case-studies', $initArguments, $progress);
            $progress->advance();
            $this->line('  ✓ Dati di base pronti; le credenziali sono nella tabella appena stampata.');

            $this->components->info('4/6 — Profili aziendali, connettori API e MCP');
            $this->line('  Creo il profilo di recupero già pronto, un connettore API statico e un connettore MCP per ogni tenant.');
            $progress->setMessage('Profili aziendali, API e MCP');
            $progress->display();
            $this->callChecked('db:seed', ['--class' => LocalIntegrationRetrievalProfilesSeeder::class, '--force' => true], $progress);
            $this->callChecked('db:seed', ['--class' => LocalIntegrationConnectorsSeeder::class, '--force' => true], $progress);
            $progress->advance();
            $this->line('  ✓ Profili di recupero, connettori API e MCP configurati.');

            $this->components->info('5/6 — Attivazione runtime MCP nei tre tenant');
            $this->line('  Abilito l’esecuzione MCP per i tre ambienti isolati.');
            $progress->setMessage('Attivazione runtime MCP');
            $progress->display();
            $previousTenant = $tenants->current();
            try {
                foreach (CaseStudyUsersSeeder::companyKeys() as $tenantId) {
                    $tenants->set($tenantId);
                    $settings->set('connector.mcp.runtime_mode', 'active', $tenantId, AppSetting::WILDCARD);
                }
            } finally {
                $tenants->set($previousTenant);
            }
            $progress->advance();
            $this->line('  ✓ Runtime MCP attivo.');

            if (! (bool) $this->option('skip-smoke')) {
                $this->components->info('6/6 — Smoke MCP read-only per azienda');
                $this->line('  Verifico che ogni connettore MCP risponda con il proprio contesto aziendale.');
                $progress->setMessage('Smoke MCP per le tre aziende');
                $progress->display();
                foreach (CaseStudyUsersSeeder::companyKeys() as $tenantId) {
                    $connection = McpConnection::withoutGlobalScopes()
                        ->where('tenant_id', $tenantId)
                        ->where('project_key', $tenantId)
                        ->where('label', 'Fixture MCP operativo locale')
                        ->first();
                    if (! $connection instanceof McpConnection) {
                        throw new RuntimeException("Missing local MCP fixture connection for {$tenantId}.");
                    }
                    $this->callChecked('mcp-connectors:smoke', [
                        '--connection' => $connection->public_id,
                        '--tool' => 'get_company_context',
                    ], $progress);
                }
                $this->line('  ✓ Smoke MCP completato per tutte le aziende.');
            } else {
                $this->components->warn('6/6 — Smoke MCP saltato (--skip-smoke).');
            }
            $progress->advance();
            $progress->finish();
            $this->newLine();
        } catch (\Throwable $exception) {
            if ($progress instanceof ProgressBar) {
                $progress->clear();
                $this->newLine();
            }
            report($exception);
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(
            'Ambiente locale pronto: ogni account case-study ha documenti, e-mail, API e MCP coerenti nel proprio tenant.',
        );

        return self::SUCCESS;
    }

    private function configureCurrentProcess(): void
    {
        // The current CLI process has already loaded config before we changed
        // .env. Mirror the persisted local gate here; Herd picks it up on the
        // next request (and config:cache is intentionally unsupported for this
        // local-only harness).
        config([
            'connector-api.ssrf.enabled' => false,
            'connector-api.ssrf.https_only' => false,
            'connector-mcp.enabled' => true,
            'connector-mcp.http.internal_endpoint_allowlist' => ['127.0.0.1', '::1', 'localhost'],
            // Run jobs created by this command inline. This makes Gmail ingest
            // deterministic and avoids waking the shared local Redis queues or
            // consuming another developer's backlog.
            'queue.default' => 'sync',
        ]);
    }

    private function emailProfile(): string
    {
        $profile = trim((string) $this->option('email-profile'));
        if (! in_array($profile, ['gold', 'demo'], true)) {
            throw new RuntimeException('--email-profile must be gold or demo.');
        }

        return $profile;
    }

    private function stageProgress(): ProgressBar
    {
        $progress = $this->output->createProgressBar(6);
        $progress->setBarWidth(24);
        $progress->setFormat('  %current%/%max% [%bar%] %percent:3s%% — %message%');
        $progress->setBarCharacter('=');
        $progress->setEmptyBarCharacter('-');
        $progress->setProgressCharacter('>');
        $progress->setMessage('Preparazione');
        $progress->start();

        return $progress;
    }

    /** @param array<string,mixed> $arguments */
    private function callChecked(string $command, array $arguments, ?ProgressBar $progress = null): void
    {
        $progress?->clear();
        try {
            $exitCode = $this->call($command, $arguments);
        } finally {
            $progress?->display();
        }
        if ($exitCode !== self::SUCCESS) {
            throw new RuntimeException("Command '{$command}' failed with exit code {$exitCode}.");
        }
    }
}

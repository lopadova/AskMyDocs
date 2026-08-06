<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\InitCaseStudiesCommand;
use App\Models\KnowledgeDocument;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\CaseStudyUsersSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;

/**
 * Feature test dell'orchestratore `demo:init-case-studies`. Verifica la colla
 * (aziende + utenti, rispetto dei flag, skip e-mail senza password) senza
 * toccare confini esterni: si usano --skip-docs/--skip-emails per evitare
 * embeddings reali e IMAP.
 */
final class InitCaseStudiesCommandTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $touchedEnv = [];

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set('default');
    }

    protected function tearDown(): void
    {
        foreach ($this->touchedEnv as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
        $this->touchedEnv = [];

        parent::tearDown();
    }

    public function test_seeds_companies_and_users_with_isolated_memberships(): void
    {
        $this->artisan('demo:init-case-studies', ['--skip-docs' => true, '--skip-emails' => true])
            ->assertExitCode(0);

        // 3 ruoli per la stessa azienda.
        $viewer = User::where('email', 'rotta@case-study.local')->first();
        $admin = User::where('email', 'rotta.admin@case-study.local')->first();
        $super = User::where('email', 'rotta.super@case-study.local')->first();

        $this->assertNotNull($viewer);
        $this->assertNotNull($admin);
        $this->assertNotNull($super);
        $this->assertTrue($viewer->hasRole('viewer'));
        $this->assertTrue($admin->hasRole('admin'));
        $this->assertTrue($super->hasRole('super-admin'));

        // Membership isolata: ognuno solo sulla propria azienda.
        $this->assertSame(
            ['rotta-logistics'],
            ProjectMembership::where('user_id', $viewer->id)->pluck('project_key')->all(),
        );
        $this->assertSame(
            ['prometeo-antincendio'],
            ProjectMembership::where('user_id', User::where('email', 'prometeo@case-study.local')->value('id'))
                ->pluck('project_key')->all(),
        );
    }

    public function test_skip_docs_ingests_no_documents(): void
    {
        $this->artisan('demo:init-case-studies', ['--skip-docs' => true, '--skip-emails' => true])
            ->assertExitCode(0);

        $this->assertSame(0, KnowledgeDocument::query()->count());
    }

    public function test_email_step_is_skipped_when_password_absent(): void
    {
        // Senza la password l'app NON deve fallire né toccare la rete: lo step
        // e-mail si salta con un warning (R14 — degrado pulito, non un crash).
        $this->forgetEnv('CONNECTOR_TEST_GMAIL_PASSWORD');

        $this->artisan('demo:init-case-studies', ['--skip-docs' => true])
            ->assertExitCode(0);

        // Aziende comunque seedate.
        $this->assertNotNull(User::where('email', 'rotta@case-study.local')->first());
    }

    public function test_generated_profile_is_built_preflighted_and_delivered_with_scoped_purge(): void
    {
        $this->setEnv('CONNECTOR_TEST_GMAIL_PASSWORD', 'pw');
        [$tester, $command] = $this->scriptedCommand([]);

        $exitCode = $tester->execute([
            '--skip-docs' => true,
            '--profile' => 'large',
            '--generate-email-dataset' => true,
            '--email-confirm-token' => 'confirmed-token',
            '--email-actor' => 'operator:init',
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(
            [
                'db:seed',
                'db:seed',
                'demo:generate-case-study-emails',
                'mail:seed-imap',
                'mail:seed-imap',
                'connector:imap:install',
                'demo:list-companies',
            ],
            array_column($command->calls, 'command'),
        );
        $this->assertSame([
            '--profile' => 'large',
            '--output' => 'storage/app/demo-email-datasets',
            '--force' => true,
            '--stats' => true,
        ], $command->calls[2]['arguments']);
        $this->assertSame([
            '--all' => true,
            '--dataset-root' => 'storage/app/demo-email-datasets',
            '--profile' => 'large',
            '--summary-only' => true,
            '--estimate-cost' => true,
        ], $command->calls[3]['arguments']);
        $this->assertSame([
            '--all' => true,
            '--dataset-root' => 'storage/app/demo-email-datasets',
            '--profile' => 'large',
            '--summary-only' => true,
            '--purge-dataset' => true,
            '--confirm-token' => 'confirmed-token',
            '--actor' => 'operator:init',
        ], $command->calls[4]['arguments']);
    }

    public function test_generated_dataset_resume_never_purges_the_mailbox(): void
    {
        $this->setEnv('CONNECTOR_TEST_GMAIL_PASSWORD', 'pw');
        [$tester, $command] = $this->scriptedCommand([]);

        $exitCode = $tester->execute([
            '--skip-docs' => true,
            '--dataset-version' => 'case-study-email-v2-large-s20260723-catalogv1',
            '--resume' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $delivery = $command->calls[3]['arguments'];
        $this->assertTrue($delivery['--resume']);
        $this->assertArrayNotHasKey('--purge-dataset', $delivery);
        $this->assertArrayNotHasKey('--purge', $delivery);
    }

    public function test_generated_profile_preflight_runs_without_an_imap_password(): void
    {
        $this->forgetEnv('CONNECTOR_TEST_GMAIL_PASSWORD');
        [$tester, $command] = $this->scriptedCommand([]);

        $exitCode = $tester->execute([
            '--skip-docs' => true,
            '--profile' => 'large',
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(
            ['db:seed', 'db:seed', 'mail:seed-imap', 'demo:list-companies'],
            array_column($command->calls, 'command'),
        );
        $this->assertTrue($command->calls[2]['arguments']['--estimate-cost']);
        $this->assertStringContainsString(
            'Delivery IMAP e connettori saltati',
            $tester->getDisplay(),
        );
    }

    public function test_generate_email_dataset_requires_a_profile_before_any_side_effect(): void
    {
        [$tester, $command] = $this->scriptedCommand([]);

        $exitCode = $tester->execute([
            '--skip-docs' => true,
            '--skip-emails' => true,
            '--generate-email-dataset' => true,
        ]);

        $this->assertSame(2, $exitCode);
        $this->assertSame([], $command->calls);
        $this->assertStringContainsString(
            '--generate-email-dataset richiede --profile',
            $tester->getDisplay(),
        );
    }

    public function test_generated_ingest_requires_the_queue_worker_dataset_root_to_match_delivery(): void
    {
        [$tester, $command] = $this->scriptedCommand([]);

        $exitCode = $tester->execute([
            '--skip-docs' => true,
            '--profile' => 'large',
            '--dataset-root' => '/tmp/a-different-email-dataset-root',
            '--ingest-emails' => true,
        ]);

        $this->assertSame(2, $exitCode);
        $this->assertSame([], $command->calls);
        $this->assertStringContainsString(
            'CASE_STUDY_EMAIL_DATASET_ROOT',
            $tester->getDisplay(),
        );
    }

    public function test_generated_ingest_requires_the_fixture_metadata_index_gate(): void
    {
        config()->set('connectors.case_study_email_dataset.require_fixture_index', false);
        [$tester, $command] = $this->scriptedCommand([]);

        $exitCode = $tester->execute([
            '--skip-docs' => true,
            '--profile' => 'large',
            '--ingest-emails' => true,
        ]);

        $this->assertSame(2, $exitCode);
        $this->assertSame([], $command->calls);
        $this->assertStringContainsString(
            'CASE_STUDY_EMAIL_REQUIRE_FIXTURE_INDEX=true',
            $tester->getDisplay(),
        );
    }

    public function test_propagates_migrate_fresh_exit_code_and_stops(): void
    {
        [$tester, $command] = $this->scriptedCommand(['migrate:fresh' => 26]);

        $exitCode = $tester->execute([
            '--fresh' => true,
            '--skip-docs' => true,
            '--skip-emails' => true,
        ]);

        $this->assertSame(26, $exitCode);
        $this->assertSame(['migrate:fresh'], array_column($command->calls, 'command'));
        $this->assertStringNotContainsString('Fatto.', $tester->getDisplay());
    }

    public function test_propagates_rbac_seeder_exit_code_and_stops(): void
    {
        [$tester, $command] = $this->scriptedCommand([
            'db:seed:'.RbacSeeder::class => 21,
        ]);

        $exitCode = $tester->execute([
            '--skip-docs' => true,
            '--skip-emails' => true,
        ]);

        $this->assertSame(21, $exitCode);
        $this->assertSame(['db:seed'], array_column($command->calls, 'command'));
        $this->assertStringNotContainsString('Fatto.', $tester->getDisplay());
    }

    public function test_propagates_case_study_seeder_exit_code_and_stops(): void
    {
        [$tester, $command] = $this->scriptedCommand([
            'db:seed:'.CaseStudyUsersSeeder::class => 22,
        ]);

        $exitCode = $tester->execute([
            '--skip-docs' => true,
            '--skip-emails' => true,
        ]);

        $this->assertSame(22, $exitCode);
        $this->assertSame(['db:seed', 'db:seed'], array_column($command->calls, 'command'));
        $this->assertStringNotContainsString('Fatto.', $tester->getDisplay());
    }

    public function test_propagates_document_ingest_exit_code_and_stops_before_email(): void
    {
        Storage::fake('kb');
        config([
            'kb.sources.disk' => 'kb',
            'kb.sources.path_prefix' => '',
        ]);
        $this->setEnv('CONNECTOR_TEST_GMAIL_PASSWORD', 'pw');
        [$tester, $command] = $this->scriptedCommand(['kb:ingest-folder' => 23]);

        // Testbench usa una base path isolata che non contiene il corpus reale;
        // punta temporaneamente al checkout per esercitare davvero almeno un
        // kb:ingest-folder, poi ripristina lo stato globale dell'app.
        $previousBasePath = $this->app->basePath();
        $this->app->setBasePath(dirname(__DIR__, 3));
        try {
            $exitCode = $tester->execute([
                '--skip-docs' => false,
                '--skip-emails' => false,
            ]);
        } finally {
            $this->app->setBasePath($previousBasePath);
        }

        $this->assertSame(
            ['db:seed', 'db:seed', 'kb:ingest-folder'],
            array_column($command->calls, 'command'),
        );
        $this->assertSame(23, $exitCode);
        $this->assertStringNotContainsString('Fatto.', $tester->getDisplay());
    }

    public function test_propagates_mail_seed_exit_code_without_installing_connectors(): void
    {
        $this->setEnv('CONNECTOR_TEST_GMAIL_PASSWORD', 'pw');
        [$tester, $command] = $this->scriptedCommand(['mail:seed-imap' => 24]);

        $exitCode = $tester->execute(['--skip-docs' => true]);

        $this->assertSame(24, $exitCode);
        $this->assertSame(
            ['db:seed', 'db:seed', 'mail:seed-imap'],
            array_column($command->calls, 'command'),
        );
        $this->assertStringNotContainsString('Fatto.', $tester->getDisplay());
    }

    public function test_propagates_connector_install_exit_code_and_stops_before_summary(): void
    {
        $this->setEnv('CONNECTOR_TEST_GMAIL_PASSWORD', 'pw');
        [$tester, $command] = $this->scriptedCommand([
            'connector:imap:install' => 25,
        ]);

        $exitCode = $tester->execute(['--skip-docs' => true]);

        $this->assertSame(25, $exitCode);
        $this->assertSame(
            ['db:seed', 'db:seed', 'mail:seed-imap', 'connector:imap:install'],
            array_column($command->calls, 'command'),
        );
        $this->assertStringNotContainsString('Fatto.', $tester->getDisplay());
    }

    public function test_propagates_summary_exit_code_without_printing_done(): void
    {
        [$tester, $command] = $this->scriptedCommand(['demo:list-companies' => 27]);

        $exitCode = $tester->execute([
            '--skip-docs' => true,
            '--skip-emails' => true,
        ]);

        $this->assertSame(27, $exitCode);
        $this->assertSame(
            ['db:seed', 'db:seed', 'demo:list-companies'],
            array_column($command->calls, 'command'),
        );
        $this->assertStringNotContainsString('Fatto.', $tester->getDisplay());
    }

    /**
     * @param  array<string,int>  $exitCodes
     * @return array{0: CommandTester, 1: ScriptedInitCaseStudiesCommand}
     */
    private function scriptedCommand(array $exitCodes): array
    {
        $command = new ScriptedInitCaseStudiesCommand($exitCodes);
        $command->setLaravel($this->app);

        return [new CommandTester($command), $command];
    }

    private function setEnv(string $key, string $value): void
    {
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        $this->touchedEnv[] = $key;
    }

    private function forgetEnv(string $key): void
    {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
        $this->touchedEnv[] = $key;
    }
}

/**
 * Test double dell'orchestratore: lascia girare handle() e sostituisce soltanto
 * i sotto-comandi, così ogni failure path esercita davvero il controllo e la
 * propagazione dell'exit code senza migrazioni distruttive, rete o provider AI.
 */
final class ScriptedInitCaseStudiesCommand extends InitCaseStudiesCommand
{
    /** @var list<array{command:string, arguments:array<string,mixed>}> */
    public array $calls = [];

    /**
     * @param  array<string,int>  $exitCodes
     */
    public function __construct(private readonly array $exitCodes)
    {
        parent::__construct();
    }

    /**
     * @param  string  $command
     * @param  array<string,mixed>  $arguments
     */
    public function call($command, array $arguments = [])
    {
        $command = (string) $command;
        $this->calls[] = compact('command', 'arguments');

        $lookupKey = $command === 'db:seed'
            ? $command.':'.(string) ($arguments['--class'] ?? '')
            : $command;

        return $this->exitCodes[$lookupKey] ?? self::SUCCESS;
    }
}

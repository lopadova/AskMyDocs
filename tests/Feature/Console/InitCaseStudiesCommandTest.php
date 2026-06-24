<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\KnowledgeDocument;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    private function forgetEnv(string $key): void
    {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
        $this->touchedEnv[] = $key;
    }
}

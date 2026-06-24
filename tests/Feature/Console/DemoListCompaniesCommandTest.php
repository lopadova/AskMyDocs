<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Tests\TestCase;

/**
 * Feature test della discovery read-only `demo:list-companies`.
 *
 * Pin: elenca i progetti con conteggi corretti e i membri; e mostra i project_key
 * ORFANI (documenti ma nessuna riga projects/membership) — lo stato "la chat non
 * trova niente" che il connettore può creare ingerendo in un project non registrato.
 */
final class DemoListCompaniesCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set('default');
    }

    public function test_lists_a_registered_project_with_counts_and_members(): void
    {
        Project::create([
            'tenant_id' => 'default',
            'project_key' => 'rotta-logistics',
            'name' => 'Rotta Sicura Logistics',
            'description' => 'logistica',
        ]);
        // 3 documenti, 2 chunk → conteggi distinguibili (3 ≠ 2) per provare
        // davvero le colonne docs/chunks (R16), non solo le stringhe.
        $d1 = $this->makeDoc('rotta-logistics', 'emails/inbox/1.md', 'seed-1');
        $d2 = $this->makeDoc('rotta-logistics', 'emails/inbox/2.md', 'seed-2');
        $this->makeDoc('rotta-logistics', 'emails/inbox/3.md', 'seed-3');
        $this->makeChunk($d1, 'rotta-logistics');
        $this->makeChunk($d2, 'rotta-logistics');
        $this->makeMember('rotta@case-study.local', 'rotta-logistics');

        $exit = Artisan::call('demo:list-companies');
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('rotta-logistics', $output);
        $this->assertStringContainsString('Rotta Sicura Logistics', $output);
        $this->assertStringContainsString('rotta@case-study.local', $output);
        // La riga della casella deve riportare docs=3, chunks=2 nelle colonne.
        $this->assertMatchesRegularExpression(
            '/rotta-logistics.*?\|\s*3\s*\|\s*2\s*\|/',
            $output,
        );
    }

    public function test_lists_connector_installations_by_project_key_column(): void
    {
        // v8.20: project_key è una COLONNA — il comando deve leggerla da lì,
        // non da config_json (regressione del rebase).
        Project::create([
            'tenant_id' => 'default',
            'project_key' => 'rotta-logistics',
            'name' => 'Rotta',
            'description' => '',
        ]);
        ConnectorInstallation::create([
            'tenant_id' => 'default',
            'connector_name' => 'imap',
            'label' => 'rotta-logistics-1',
            'project_key' => 'rotta-logistics',
            'config_json' => ['auth_mode' => 'basic'],
            'status' => ConnectorInstallation::STATUS_ACTIVE,
        ]);

        $exit = Artisan::call('demo:list-companies', ['--tenant' => 'default']);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        // La riga rotta-logistics mostra il connettore (non "—").
        $this->assertMatchesRegularExpression('/rotta-logistics.*imap#\d+\(active\)/', $output);
    }

    public function test_surfaces_orphan_project_keys_without_a_project_row(): void
    {
        // Documenti in un project_key SENZA riga projects né membership.
        $this->makeDoc('connector-imap', 'emails/inbox/9.md', 'orphan-1');

        $exit = Artisan::call('demo:list-companies');
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('connector-imap', $output);
        $this->assertStringContainsString('orfano', $output);
    }

    public function test_tenant_filter_excludes_other_tenants(): void
    {
        Project::create([
            'tenant_id' => 'default',
            'project_key' => 'rotta-logistics',
            'name' => 'Rotta',
            'description' => '',
        ]);
        Project::create([
            'tenant_id' => 'acme',
            'project_key' => 'acme-kb',
            'name' => 'Acme',
            'description' => '',
        ]);

        $exit = Artisan::call('demo:list-companies', ['--tenant' => 'default']);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('rotta-logistics', $output);
        $this->assertStringNotContainsString('acme-kb', $output);
    }

    private function makeDoc(string $projectKey, string $sourcePath, string $seed): KnowledgeDocument
    {
        return KnowledgeDocument::create([
            'project_key' => $projectKey,
            'source_path' => $sourcePath,
            'source_type' => 'email',
            'title' => 'Doc '.$seed,
            'mime_type' => 'text/markdown',
            'language' => 'it',
            'access_scope' => 'internal',
            'status' => 'active',
            'document_hash' => hash('sha256', $seed),
            'version_hash' => hash('sha256', $seed),
            'metadata' => [],
            'indexed_at' => now(),
        ]);
    }

    private function makeChunk(KnowledgeDocument $doc, string $projectKey): KnowledgeChunk
    {
        return KnowledgeChunk::create([
            'knowledge_document_id' => $doc->id,
            'project_key' => $projectKey,
            'chunk_order' => 0,
            'chunk_hash' => hash('sha256', 'chunk-'.$doc->id),
            'heading_path' => 'Intro',
            'chunk_text' => 'corpo email',
            'metadata' => [],
        ]);
    }

    private function makeMember(string $email, string $projectKey): User
    {
        $user = User::create([
            'name' => 'Member',
            'email' => $email,
            'password' => Hash::make('secret123'),
        ]);
        ProjectMembership::create([
            'user_id' => $user->id,
            'project_key' => $projectKey,
            'role' => 'member',
            'scope_allowlist' => null,
        ]);

        return $user;
    }
}

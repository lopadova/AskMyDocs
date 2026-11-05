<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Http\Middleware\EnforceMcpScope;
use App\Mcp\Tools\KbImportWikiTool;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Services\Kb\Import\KbWikiImportService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Laravel\Mcp\Request;
use Tests\TestCase;

/**
 * v8.38/W4c (ADR 0032 §10/§11) — propose-only MCP surface. Same
 * mutating-tool control set as `KbProposeTextCorrectionTool`: never writes
 * the corpus directly.
 */
final class KbImportWikiToolTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = app(TenantContext::class)->current();
        Queue::fake();
        config(['kb.wiki_export.enabled' => true, 'kb.wiki_export.import_candidates_per_hour' => 30]);
    }

    private function user(): User
    {
        $user = User::create([
            'name' => 'Importer',
            'email' => 'importer-'.uniqid().'@example.test',
            'password' => Hash::make('secret-secret'),
        ])->fresh();
        ProjectMembership::create(['tenant_id' => $this->tenantId, 'user_id' => $user->id, 'project_key' => 'default', 'role' => 'member']);

        return $user;
    }

    private function markdown(string $slug): string
    {
        return <<<MD
        ---
        id: {$slug}-id
        slug: {$slug}
        type: runbook
        status: accepted
        ---

        # Brand new

        Body.
        MD;
    }

    public function test_it_proposes_a_candidate_for_a_bound_principal(): void
    {
        Auth::setUser($this->user());

        $response = (new KbImportWikiTool())->handle(
            new Request(['project_key' => 'default', 'markdown' => $this->markdown('brand-new-page')]),
            app(KbWikiImportService::class),
            app(TenantContext::class),
        );

        $payload = json_decode((string) $response->content(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('created', $payload['status']);
        $this->assertNotNull($payload['approval']);
    }

    public function test_it_refuses_when_no_principal_is_bound(): void
    {
        Auth::forgetGuards();

        $response = (new KbImportWikiTool())->handle(
            new Request(['project_key' => 'default', 'markdown' => $this->markdown('brand-new-page')]),
            app(KbWikiImportService::class),
            app(TenantContext::class),
        );

        $this->assertTrue($response->isError());
        $this->assertStringContainsString('principal', (string) $response->content());
    }

    public function test_it_refuses_empty_markdown(): void
    {
        Auth::setUser($this->user());

        $response = (new KbImportWikiTool())->handle(
            new Request(['project_key' => 'default', 'markdown' => '   ']),
            app(KbWikiImportService::class),
            app(TenantContext::class),
        );

        $this->assertTrue($response->isError());
    }

    public function test_the_tool_is_gated_by_the_propose_scope_not_the_write_scope(): void
    {
        $reflection = new \ReflectionClass(EnforceMcpScope::class);
        $const = $reflection->getConstant('PROPOSE_TOOL_NAMES');

        $this->assertArrayHasKey('kbimportwikitool', $const, 'KbImportWikiTool must sit behind mcp:tools:propose, not the stronger mcp:tools:write.');
    }

    public function test_the_tool_is_not_annotated_read_only(): void
    {
        $reflection = new \ReflectionClass(KbImportWikiTool::class);
        $names = array_map(static fn ($a): string => $a->getName(), $reflection->getAttributes());

        $this->assertNotContains(\Laravel\Mcp\Server\Tools\Annotations\IsReadOnly::class, $names);
    }
}

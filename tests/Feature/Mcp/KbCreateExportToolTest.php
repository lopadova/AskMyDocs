<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Mcp\Tools\KbCreateExportTool;
use App\Models\KbWikiExportRequest;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Services\Kb\Export\KbWikiExportRequestService;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Laravel\Mcp\Request;
use Tests\TestCase;

/**
 * v8.38/W4c (ADR 0032 §11) — MCP write surface for starting a portable
 * wiki export. Authorized like the HTTP endpoint: `mcp:tools:write` is a
 * DIFFERENT axis than the bound principal's Laravel role, so this tool
 * re-checks admin/super-admin explicitly.
 */
final class KbCreateExportToolTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = app(TenantContext::class)->current();
        $this->seed(RbacSeeder::class);
        Queue::fake();
        config(['kb.wiki_export.enabled' => true, 'kb.wiki_export.create_requests_per_hour' => 10]);
    }

    private function admin(): User
    {
        $user = User::create([
            'name' => 'Admin',
            'email' => 'admin-'.uniqid().'@example.test',
            'password' => Hash::make('secret-secret'),
        ])->fresh();
        $user->assignRole('admin');
        ProjectMembership::create(['tenant_id' => $this->tenantId, 'user_id' => $user->id, 'project_key' => 'default', 'role' => 'member']);

        return $user;
    }

    private function viewer(): User
    {
        $user = User::create([
            'name' => 'Viewer',
            'email' => 'viewer-'.uniqid().'@example.test',
            'password' => Hash::make('secret-secret'),
        ])->fresh();
        $user->assignRole('viewer');

        return $user;
    }

    public function test_it_answers_disabled_when_the_feature_flag_is_off(): void
    {
        config(['kb.wiki_export.enabled' => false]);
        Auth::setUser($this->admin());

        $response = (new KbCreateExportTool())->handle(
            new Request(['project_key' => 'default']),
            app(KbWikiExportRequestService::class),
            app(TenantContext::class),
        );

        $this->assertSame(['disabled' => true, 'flag' => 'KB_WIKI_EXPORT_ENABLED'], json_decode((string) $response->content(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function test_it_refuses_a_principal_that_is_not_admin_or_super_admin(): void
    {
        Auth::setUser($this->viewer());

        $response = (new KbCreateExportTool())->handle(
            new Request(['project_key' => 'default']),
            app(KbWikiExportRequestService::class),
            app(TenantContext::class),
        );

        $this->assertTrue($response->isError());
        $this->assertStringContainsString('export permission', (string) $response->content());
    }

    public function test_it_starts_an_export_for_an_admin_principal(): void
    {
        Auth::setUser($this->admin());

        $response = (new KbCreateExportTool())->handle(
            new Request(['project_key' => 'default']),
            app(KbWikiExportRequestService::class),
            app(TenantContext::class),
        );

        $payload = json_decode((string) $response->content(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('default', $payload['project_key']);
        $this->assertSame(KbWikiExportRequest::STATUS_QUEUED, $payload['status']);
        $this->assertDatabaseHas('kb_wiki_export_requests', ['id' => $payload['id'], 'tenant_id' => $this->tenantId]);
    }

    public function test_it_enforces_a_per_principal_rate_limit(): void
    {
        config(['kb.wiki_export.create_requests_per_hour' => 1]);
        $admin = $this->admin();
        $tool = new KbCreateExportTool();

        Auth::setUser($admin);
        $tool->handle(new Request(['project_key' => 'default']), app(KbWikiExportRequestService::class), app(TenantContext::class));

        // A second, DIFFERENT project defeats requestExport()'s own
        // idempotency key (different project_key -> different key), so the
        // rate limiter — not the idempotency dedup — is what must refuse
        // this second call.
        Auth::setUser($admin);
        $response = $tool->handle(new Request(['project_key' => 'other-project']), app(KbWikiExportRequestService::class), app(TenantContext::class));

        $this->assertTrue($response->isError());
        $this->assertStringContainsString('rate limit', (string) $response->content());
    }

    public function test_the_tool_is_not_annotated_read_only(): void
    {
        $reflection = new \ReflectionClass(KbCreateExportTool::class);
        $names = array_map(static fn ($a): string => $a->getName(), $reflection->getAttributes());

        $this->assertNotContains(\Laravel\Mcp\Server\Tools\Annotations\IsReadOnly::class, $names);
    }
}

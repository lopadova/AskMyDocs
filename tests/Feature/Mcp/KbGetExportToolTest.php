<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Mcp\Tools\KbGetExportTool;
use App\Models\KbWikiExportRequest;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Laravel\Mcp\Request;
use Tests\TestCase;

/**
 * v8.38/W4c (ADR 0032 §11) — read-only MCP status surface for a wiki export
 * request, tenant-scoped (R30) exactly like
 * {@see \App\Http\Controllers\Api\Admin\KbWikiExportController::show()}.
 * Also role-gated (`admin`/`super-admin`) exactly like
 * {@see \App\Mcp\Tools\KbCreateExportTool} — independent-review fix
 * (PR #511 GA merge), see the tool's own docblock.
 */
final class KbGetExportToolTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = app(TenantContext::class)->current();
        $this->seed(RbacSeeder::class);
        config(['kb.wiki_export.enabled' => true]);
    }

    private function admin(): User
    {
        $user = User::create([
            'name' => 'Admin',
            'email' => 'admin-'.uniqid().'@example.test',
            'password' => Hash::make('secret-secret'),
        ])->fresh();
        $user->assignRole('admin');

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

    private function callTool(string $id): array
    {
        $response = (new KbGetExportTool())->handle(new Request(['id' => $id]), app(TenantContext::class));

        return json_decode((string) $response->content(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_it_answers_disabled_when_the_feature_flag_is_off(): void
    {
        config(['kb.wiki_export.enabled' => false]);
        Auth::setUser($this->admin());

        $payload = $this->callTool('does-not-matter');

        $this->assertSame(['disabled' => true, 'flag' => 'KB_WIKI_EXPORT_ENABLED'], $payload);
    }

    public function test_it_refuses_when_no_mcp_principal_is_bound(): void
    {
        $response = (new KbGetExportTool())->handle(new Request(['id' => 'does-not-matter']), app(TenantContext::class));

        $this->assertTrue($response->isError());
        $this->assertStringContainsString('No MCP principal', (string) $response->content());
    }

    /**
     * Independent-review regression (PR #511 GA merge) — before the fix,
     * a `viewer` (or any tenant member) holding an MCP token could read
     * another user's export-request status/error metadata through this
     * tool even though the HTTP surface and `KbCreateExportTool` both
     * restrict the capability to admins: `#[IsReadOnly]` maps this tool to
     * the baseline `mcp:read` scope every valid token carries, which is
     * not the same axis as a Laravel role.
     */
    public function test_it_refuses_a_principal_that_is_not_admin_or_super_admin(): void
    {
        $request = KbWikiExportRequest::create([
            'tenant_id' => $this->tenantId,
            'project_key' => 'default',
            'status' => KbWikiExportRequest::STATUS_QUEUED,
            'options_json' => ['format' => 'llm-wiki', 'include_images' => false],
            'idempotency_key' => hash('sha256', 'viewer-test'),
        ]);
        Auth::setUser($this->viewer());

        $response = (new KbGetExportTool())->handle(new Request(['id' => $request->id]), app(TenantContext::class));

        $this->assertTrue($response->isError());
        $this->assertStringContainsString('export permission', (string) $response->content());
    }

    public function test_it_refuses_an_unknown_id(): void
    {
        Auth::setUser($this->admin());

        $response = (new KbGetExportTool())->handle(new Request(['id' => (string) \Illuminate\Support\Str::uuid()]), app(TenantContext::class));

        $this->assertTrue($response->isError());
    }

    public function test_it_reports_status_for_a_known_request(): void
    {
        $request = KbWikiExportRequest::create([
            'tenant_id' => $this->tenantId,
            'project_key' => 'default',
            'status' => KbWikiExportRequest::STATUS_QUEUED,
            'options_json' => ['format' => 'llm-wiki', 'include_images' => false],
            'idempotency_key' => hash('sha256', 'test'),
        ]);
        Auth::setUser($this->admin());

        $payload = $this->callTool($request->id);

        $this->assertSame($request->id, $payload['id']);
        $this->assertSame(KbWikiExportRequest::STATUS_QUEUED, $payload['status']);
        $this->assertNull($payload['download_url']);
    }

    public function test_it_does_not_see_another_tenants_export_request(): void
    {
        $tenants = app(TenantContext::class);
        $previous = $tenants->current();
        $tenants->set('other-tenant');
        $other = KbWikiExportRequest::create([
            'tenant_id' => 'other-tenant',
            'project_key' => 'default',
            'status' => KbWikiExportRequest::STATUS_QUEUED,
            'options_json' => ['format' => 'llm-wiki', 'include_images' => false],
            'idempotency_key' => hash('sha256', 'other'),
        ]);
        $tenants->set($previous);
        Auth::setUser($this->admin());

        $response = (new KbGetExportTool())->handle(new Request(['id' => $other->id]), app(TenantContext::class));

        $this->assertTrue($response->isError());
    }

    public function test_the_tool_is_annotated_read_only(): void
    {
        $reflection = new \ReflectionClass(KbGetExportTool::class);
        $names = array_map(static fn ($a): string => $a->getName(), $reflection->getAttributes());

        $this->assertContains(\Laravel\Mcp\Server\Tools\Annotations\IsReadOnly::class, $names);
    }
}

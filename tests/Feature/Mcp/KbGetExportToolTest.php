<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Mcp\Tools\KbGetExportTool;
use App\Models\KbWikiExportRequest;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;
use Tests\TestCase;

/**
 * v8.38/W4c (ADR 0032 §11) — read-only MCP status surface for a wiki export
 * request, tenant-scoped (R30) exactly like
 * {@see \App\Http\Controllers\Api\Admin\KbWikiExportController::show()}.
 */
final class KbGetExportToolTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = app(TenantContext::class)->current();
        config(['kb.wiki_export.enabled' => true]);
    }

    private function callTool(string $id): array
    {
        $response = (new KbGetExportTool())->handle(new Request(['id' => $id]), app(TenantContext::class));

        return json_decode((string) $response->content(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_it_answers_disabled_when_the_feature_flag_is_off(): void
    {
        config(['kb.wiki_export.enabled' => false]);

        $payload = $this->callTool('does-not-matter');

        $this->assertSame(['disabled' => true, 'flag' => 'KB_WIKI_EXPORT_ENABLED'], $payload);
    }

    public function test_it_refuses_an_unknown_id(): void
    {
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

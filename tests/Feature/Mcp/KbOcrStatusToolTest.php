<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Mcp\Tools\KbOcrStatusTool;
use App\Models\KnowledgeDocument;
use App\Services\Kb\Ocr\OcrService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;
use Tests\TestCase;

/**
 * v8.36 / ADR 0029 — the MCP read surface of OCR (R44), tenant-scoped (R30).
 */
final class KbOcrStatusToolTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->reset();
        config(['kb.ocr.driver' => 'fake', 'kb.ocr.enabled' => true]);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->reset();
        parent::tearDown();
    }

    private function doc(array $metadata = []): KnowledgeDocument
    {
        $path = 'scans/'.uniqid().'.png';

        return KnowledgeDocument::create([
            'project_key' => 'legal', 'source_type' => 'image', 'title' => 'Scan',
            'source_path' => $path, 'mime_type' => 'image/png', 'language' => 'en', 'access_scope' => 'internal',
            'status' => 'active', 'document_hash' => hash('sha256', $path), 'version_hash' => hash('sha256', $path),
            'metadata' => $metadata,
        ]);
    }

    private function callTool(int $id): array
    {
        $response = (new KbOcrStatusTool())->handle(new Request(['document_id' => $id]), app(OcrService::class), app(TenantContext::class));

        return json_decode((string) $response->content(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_it_reports_the_recorded_ocr_facts(): void
    {
        $doc = $this->doc(['converter' => ['page_count' => 1, 'provenance' => 'ocr', 'ocr' => [
            'driver' => 'fake', 'reason' => 'image', 'mean_confidence' => 0.9, 'min_confidence' => 0.9, 'figures' => 0,
            'pages' => [['number' => 1, 'confidence' => 0.9, 'figures' => 0]],
        ]]]);

        $payload = $this->callTool($doc->id);

        $this->assertTrue($payload['ocr']);
        $this->assertSame('fake', $payload['driver']);
        $this->assertSame('image', $payload['reason']);
        $this->assertSame(1, $payload['page_count']);
        $this->assertSame(0.9, $payload['pages'][0]['confidence']);
    }

    public function test_it_reports_a_non_ocr_document_honestly(): void
    {
        $payload = $this->callTool($this->doc()->id);

        $this->assertFalse($payload['ocr']);
        $this->assertSame([], $payload['pages']);
        $this->assertTrue($payload['enabled']);
    }

    public function test_it_does_not_see_another_tenants_document(): void
    {
        app(TenantContext::class)->set('other-tenant');
        $other = $this->doc();
        app(TenantContext::class)->reset();

        $response = (new KbOcrStatusTool())->handle(new Request(['document_id' => $other->id]), app(OcrService::class), app(TenantContext::class));

        $this->assertTrue($response->isError());
    }

    public function test_the_tool_is_annotated_read_only(): void
    {
        $reflection = new \ReflectionClass(KbOcrStatusTool::class);
        $names = array_map(static fn ($a): string => $a->getName(), $reflection->getAttributes());

        $this->assertContains(\Laravel\Mcp\Server\Tools\Annotations\IsReadOnly::class, $names);
        $this->assertContains(\Laravel\Mcp\Server\Tools\Annotations\IsIdempotent::class, $names);
    }
}

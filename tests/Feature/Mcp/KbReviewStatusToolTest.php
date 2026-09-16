<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Mcp\Tools\KbReviewStatusTool;
use App\Models\KbDocumentPageReview;
use App\Models\KnowledgeDocument;
use App\Services\Kb\Review\KbReviewService;
use App\Support\Canonical\GenerationSource;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;
use Tests\TestCase;

/**
 * v8.37/W3 / ADR 0031 §8 — the MCP read surface of Digitization Review,
 * tenant-scoped (R30). Read-only by design (§8): no MCP write of review
 * status or approval exists.
 */
final class KbReviewStatusToolTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->reset();
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->reset();
        parent::tearDown();
    }

    private function doc(array $over = []): KnowledgeDocument
    {
        return KnowledgeDocument::create(array_merge([
            'tenant_id' => (string) app(TenantContext::class)->current(),
            'project_key' => 'eng',
            'source_type' => 'image',
            'source_path' => 'scans/'.bin2hex(random_bytes(4)).'.pdf',
            'title' => 'Scanned contract',
            'mime_type' => 'application/pdf',
            'status' => 'active',
            'document_hash' => str_repeat('a', 64),
            'version_hash' => bin2hex(random_bytes(16)),
            'is_canonical' => false,
            'generation_source' => GenerationSource::Auto->value,
        ], $over));
    }

    private function callTool(int $id): array
    {
        $response = (new KbReviewStatusTool())->handle(
            new Request(['document_id' => $id]),
            app(KbReviewService::class),
            app(TenantContext::class),
        );

        return json_decode((string) $response->content(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_it_answers_disabled_when_the_feature_flag_is_off(): void
    {
        config(['kb.review.enabled' => false]);
        $doc = $this->doc();

        $payload = $this->callTool($doc->id);

        $this->assertSame(['disabled' => true, 'flag' => 'KB_DIGITIZATION_REVIEW_ENABLED'], $payload);
    }

    public function test_it_reports_the_review_summary_when_enabled(): void
    {
        config(['kb.review.enabled' => true]);
        $doc = $this->doc();
        KbDocumentPageReview::create([
            'tenant_id' => (string) $doc->tenant_id,
            'knowledge_document_id' => $doc->id,
            'page_number' => 1,
            'status' => KbDocumentPageReview::STATUS_REVIEWED,
        ]);
        KbDocumentPageReview::create([
            'tenant_id' => (string) $doc->tenant_id,
            'knowledge_document_id' => $doc->id,
            'page_number' => 2,
            'status' => KbDocumentPageReview::STATUS_UNREVIEWED,
        ]);

        $payload = $this->callTool($doc->id);

        $this->assertSame(['total' => 2, 'reviewed' => 1, 'unreviewed' => 1], $payload);
    }

    public function test_a_non_integer_document_id_is_refused_not_truncated(): void
    {
        config(['kb.review.enabled' => true]);
        $doc = $this->doc();
        $truncatesTo = "{$doc->id}.5";

        $response = (new KbReviewStatusTool())->handle(
            new Request(['document_id' => $truncatesTo]),
            app(KbReviewService::class),
            app(TenantContext::class),
        );

        $this->assertTrue($response->isError(), 'a non-integer document_id must be refused, never truncated into a real document\'s id');
        $this->assertStringContainsString('positive integer', (string) $response->content());
    }

    public function test_it_does_not_see_another_tenants_document(): void
    {
        config(['kb.review.enabled' => true]);
        app(TenantContext::class)->set('other-tenant');
        $other = $this->doc();
        app(TenantContext::class)->reset();

        $response = (new KbReviewStatusTool())->handle(
            new Request(['document_id' => $other->id]),
            app(KbReviewService::class),
            app(TenantContext::class),
        );

        $this->assertTrue($response->isError());
    }

    public function test_the_tool_is_annotated_read_only(): void
    {
        $reflection = new \ReflectionClass(KbReviewStatusTool::class);
        $names = array_map(static fn ($a): string => $a->getName(), $reflection->getAttributes());

        $this->assertContains(\Laravel\Mcp\Server\Tools\Annotations\IsReadOnly::class, $names);
        $this->assertContains(\Laravel\Mcp\Server\Tools\Annotations\IsIdempotent::class, $names);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Mcp\Tools\KbProposeTextCorrectionTool;
use App\Models\KbTextCorrectionCandidate;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Services\Kb\Review\KbReviewService;
use App\Support\Canonical\GenerationSource;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;
use Tests\TestCase;

/**
 * v8.37/W3b (ADR 0031 §6) — the ONE MCP write toward the corpus this cycle
 * adds; even it writes only a correction CANDIDATE, over the SAME
 * {@see KbReviewService::proposeCorrection()} core the future review UI's
 * "propose" action will use (R44). Tenant-scoped (R30); idempotent
 * (a replayed call returns the same candidate).
 */
final class KbProposeTextCorrectionToolTest extends TestCase
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
            'metadata' => ['converter' => ['page_count' => 1]],
        ], $over));
    }

    private function docWithPage1(string $body): KnowledgeDocument
    {
        $doc = $this->doc();
        $markdown = "# scan\n\n## Page 1\n\n{$body}\n";
        KnowledgeChunk::create([
            'tenant_id' => (string) $doc->tenant_id,
            'knowledge_document_id' => $doc->id,
            'project_key' => $doc->project_key,
            'chunk_order' => 0,
            'chunk_hash' => hash('sha256', $markdown),
            'chunk_text' => $markdown,
            'metadata' => [],
        ]);

        return $doc;
    }

    private function invokeTool(array $args): \Laravel\Mcp\Response
    {
        return (new KbProposeTextCorrectionTool())->handle(
            new Request($args),
            app(KbReviewService::class),
            app(TenantContext::class),
        );
    }

    public function test_it_answers_disabled_when_the_feature_flag_is_off(): void
    {
        config(['kb.review.enabled' => false]);
        $doc = $this->docWithPage1('Bod is the supplier.');

        $response = $this->invokeTool(['document_id' => $doc->id, 'page' => 1, 'old_text' => 'Bod', 'new_text' => 'Bob']);

        $payload = json_decode((string) $response->content(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['disabled' => true, 'flag' => 'KB_DIGITIZATION_REVIEW_ENABLED'], $payload);
    }

    public function test_a_valid_proposal_creates_a_pending_candidate(): void
    {
        config(['kb.review.enabled' => true]);
        $doc = $this->docWithPage1('Bod is the supplier.');

        $response = $this->invokeTool([
            'document_id' => $doc->id,
            'page' => 1,
            'old_text' => 'Bod',
            'new_text' => 'Bob',
            'rationale' => 'likely OCR misread',
        ]);

        $this->assertFalse($response->isError());
        $payload = json_decode((string) $response->content(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('pending', $payload['status']);
        $this->assertSame($doc->id, $payload['document_id']);
        $this->assertSame(1, $payload['page_number']);
        $candidate = KbTextCorrectionCandidate::query()->findOrFail($payload['candidate_id']);
        $this->assertSame('Bod', $candidate->old_text);
        $this->assertSame('Bob', $candidate->new_text);
        $this->assertStringStartsWith('user:', $candidate->proposed_by);
    }

    public function test_a_replayed_proposal_returns_the_same_candidate(): void
    {
        config(['kb.review.enabled' => true]);
        $doc = $this->docWithPage1('Bod is the supplier.');
        $args = ['document_id' => $doc->id, 'page' => 1, 'old_text' => 'Bod', 'new_text' => 'Bob'];

        $first = json_decode((string) $this->invokeTool($args)->content(), true, flags: JSON_THROW_ON_ERROR);
        $second = json_decode((string) $this->invokeTool($args)->content(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame($first['candidate_id'], $second['candidate_id']);
        $this->assertDatabaseCount('kb_text_correction_candidates', 1);
    }

    public function test_a_missing_document_id_is_refused(): void
    {
        config(['kb.review.enabled' => true]);

        $response = $this->invokeTool(['page' => 1, 'old_text' => 'x', 'new_text' => 'y']);

        $this->assertTrue($response->isError());
    }

    public function test_a_non_integer_document_id_is_refused_not_truncated(): void
    {
        config(['kb.review.enabled' => true]);
        $doc = $this->docWithPage1('Bod is the supplier.');

        $response = $this->invokeTool(['document_id' => "{$doc->id}.5", 'page' => 1, 'old_text' => 'Bod', 'new_text' => 'Bob']);

        $this->assertTrue($response->isError(), 'a non-integer document_id must be refused, never truncated into a real document\'s id');
    }

    public function test_an_unknown_document_is_refused(): void
    {
        config(['kb.review.enabled' => true]);

        $response = $this->invokeTool(['document_id' => 999999, 'page' => 1, 'old_text' => 'x', 'new_text' => 'y']);

        $this->assertTrue($response->isError());
        $this->assertStringContainsString('not found', (string) $response->content());
    }

    public function test_it_does_not_see_another_tenants_document(): void
    {
        config(['kb.review.enabled' => true]);
        app(TenantContext::class)->set('other-tenant');
        $other = $this->docWithPage1('Bod is the supplier.');
        app(TenantContext::class)->reset();

        $response = $this->invokeTool(['document_id' => $other->id, 'page' => 1, 'old_text' => 'Bod', 'new_text' => 'Bob']);

        $this->assertTrue($response->isError());
        $this->assertDatabaseCount('kb_text_correction_candidates', 0);
    }

    public function test_an_ambiguous_old_text_is_refused(): void
    {
        config(['kb.review.enabled' => true]);
        $doc = $this->docWithPage1('word word appears twice.');

        $response = $this->invokeTool(['document_id' => $doc->id, 'page' => 1, 'old_text' => 'word', 'new_text' => 'term']);

        $this->assertTrue($response->isError());
    }

    public function test_oversize_new_text_is_refused(): void
    {
        config(['kb.review.enabled' => true]);
        $doc = $this->docWithPage1('Bod is the supplier.');

        $response = $this->invokeTool(['document_id' => $doc->id, 'page' => 1, 'old_text' => 'Bod', 'new_text' => str_repeat('x', 4001)]);

        $this->assertTrue($response->isError());
    }

    public function test_the_tool_is_not_annotated_read_only(): void
    {
        $reflection = new \ReflectionClass(KbProposeTextCorrectionTool::class);
        $names = array_map(static fn ($a): string => $a->getName(), $reflection->getAttributes());

        $this->assertNotContains(\Laravel\Mcp\Server\Tools\Annotations\IsReadOnly::class, $names, 'a write tool must not be annotated read-only');
        $this->assertContains(\Laravel\Mcp\Server\Tools\Annotations\IsIdempotent::class, $names);
    }
}

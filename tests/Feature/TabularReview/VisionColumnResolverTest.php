<?php

declare(strict_types=1);

namespace Tests\Feature\TabularReview;

use App\Ai\AiManager;
use App\Models\KnowledgeDocument;
use App\Services\TabularReview\VisionColumnResolver;
use App\Support\TabularReview\CellFlag;
use App\Support\TabularReview\FormatType;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * v8.40/W6 (ADR 0034) — the `agent: vision` resolver. One vision-LLM call per
 * (review, document, column) over the document's OCR-extracted figures or,
 * when none exist, over the document's own file when it is itself an image.
 *
 * R14: every outcome other than the OFF-flag case is a definite content
 * array — no figures, a provider failure, and unparseable output all return
 * a red-flagged cell, never null and never a silent skip.
 */
final class VisionColumnResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->reset();

        // openai SDK config shape since v8.16/W2 (no-tools chat → SDK /responses).
        config()->set('ai.default', 'openai');
        config()->set('ai.providers.openai.key', 'test-key');
        config()->set('ai.providers.openai.models.text.default', 'gpt-4o-mini');
    }

    private function doc(array $overrides = []): KnowledgeDocument
    {
        $path = $overrides['source_path'] ?? 'catalog/'.uniqid().'.md';
        unset($overrides['source_path']);

        return KnowledgeDocument::create(array_merge([
            'project_key' => 'fashion',
            'source_type' => 'markdown',
            'title' => 'Catalog Page',
            'source_path' => $path,
            'mime_type' => 'text/markdown',
            'document_hash' => hash('sha256', $path),
            'version_hash' => hash('sha256', $path.'v'),
            'status' => 'active',
            'metadata' => [],
        ], $overrides));
    }

    private function aiPayload(string $text): array
    {
        return self::openAiSdkResponsesBody($text);
    }

    // ── OFF flag ─────────────────────────────────────────────────────

    public function test_disabled_flag_returns_null_and_never_touches_the_provider(): void
    {
        config(['kb.tabular_review.vision.enabled' => false]);

        // R26 — prove no-call: AiManager (the expensive/risky dependency) is
        // never touched when the feature is off. OcrService is `final` and
        // cannot be mocked (PHPUnit ClassIsFinalException), so the "no OCR
        // read either" half of this contract is proven structurally instead:
        // the OFF check in VisionColumnResolver::resolve() is the first line,
        // strictly before any call into resolveImages()/OcrService — see the
        // source. Http::assertNothingSent() below additionally proves no
        // network egress of any kind happened.
        $aiMock = $this->createMock(AiManager::class);
        $aiMock->expects($this->never())->method('provider');
        $this->app->instance(AiManager::class, $aiMock);

        Http::fake(['*' => Http::response(['error' => 'should_not_be_called'], 500)]);

        $resolver = $this->app->make(VisionColumnResolver::class);
        $doc = $this->doc();

        $result = $resolver->resolve($doc, 'What colour is the garment?', FormatType::TEXT, []);

        $this->assertNull($result);
        Http::assertNothingSent();
    }

    // ── OCR figures present ─────────────────────────────────────────

    public function test_figures_present_loads_images_and_calls_the_provider_capped_at_max(): void
    {
        config(['kb.tabular_review.vision.enabled' => true, 'kb.tabular_review.vision.max_images_per_cell' => 4]);
        Storage::fake('kb');

        $figuresDir = 'catalog/page.md.ocr/'.str_repeat('a', 64);
        // 5 figures on disk — more than the cap (4) — to prove the cap wins.
        for ($i = 1; $i <= 5; $i++) {
            Storage::disk('kb')->put("{$figuresDir}/images/fig-1-{$i}.png", "PNGBYTES{$i}");
        }

        $doc = $this->doc([
            'source_path' => 'catalog/page.md',
            'metadata' => [
                'converter' => ['ocr' => [
                    'figures' => 5,
                    'figures_dir' => $figuresDir,
                ]],
            ],
        ]);

        Http::fake(['*' => Http::response(
            $this->aiPayload('{"summary":"Navy blue wool coat","flag":"green","reasoning":"Visible in the photos."}'),
            200,
        )]);

        $resolver = $this->app->make(VisionColumnResolver::class);
        $result = $resolver->resolve($doc, 'What colour is the garment?', FormatType::TEXT, []);

        $this->assertNotNull($result);
        $this->assertSame('Navy blue wool coat', $result['summary']);
        $this->assertSame(CellFlag::GREEN->value, $result['flag']);
        // Capped at max_images_per_cell — never all 5 written figures.
        $this->assertCount(4, $result['citations']);

        Http::assertSent(function ($request): bool {
            $body = $request->body();

            return substr_count($body, '"type":"input_image"') === 4;
        });
    }

    // ── No figures, standalone image document ───────────────────────

    public function test_no_figures_but_standalone_image_document_makes_a_single_image_call(): void
    {
        config(['kb.tabular_review.vision.enabled' => true]);
        Storage::fake('kb');
        Storage::disk('kb')->put('catalog/sku-1.jpg', 'JPEGBYTES');

        $doc = $this->doc([
            'source_path' => 'catalog/sku-1.jpg',
            'mime_type' => 'image/jpeg',
            'metadata' => [],
        ]);

        Http::fake(['*' => Http::response(
            $this->aiPayload('{"summary":"Red cotton dress","flag":"green","reasoning":"Product photo."}'),
            200,
        )]);

        $resolver = $this->app->make(VisionColumnResolver::class);
        $result = $resolver->resolve($doc, 'What colour is the garment?', FormatType::TEXT, []);

        $this->assertNotNull($result);
        $this->assertSame('Red cotton dress', $result['summary']);
        $this->assertCount(1, $result['citations']);
        $this->assertSame('sku-1.jpg', $result['citations'][0]['chunk_id']);

        Http::assertSent(function ($request): bool {
            return substr_count($request->body(), '"type":"input_image"') === 1;
        });
    }

    // ── Neither source ───────────────────────────────────────────────

    public function test_neither_figures_nor_image_document_returns_a_definite_red_cell_without_calling_the_provider(): void
    {
        config(['kb.tabular_review.vision.enabled' => true]);
        Storage::fake('kb');

        $doc = $this->doc(['mime_type' => 'text/markdown', 'metadata' => []]);

        Http::fake(['*' => Http::response(['error' => 'should_not_be_called'], 500)]);

        $resolver = $this->app->make(VisionColumnResolver::class);
        $result = $resolver->resolve($doc, 'What colour is the garment?', FormatType::TEXT, []);

        $this->assertNotNull($result);
        $this->assertNull($result['summary']);
        $this->assertSame(CellFlag::RED->value, $result['flag']);
        $this->assertSame([], $result['citations']);
        $this->assertStringContainsString('No visual evidence', $result['reasoning']);

        Http::assertNothingSent();
    }

    // ── Malformed recorded prefix (independent-review must-fix) ──────

    public function test_a_prefix_that_cannot_name_a_path_degrades_to_a_red_cell_instead_of_throwing(): void
    {
        // StorageNamespace::recordedPrefix() returns a legacy/malformed
        // prefix like `../outside` verbatim by design — its own contract
        // says composing a path from it "throws, in whatever ran next"
        // unless the caller guards with prefixCanNamePath() first (the
        // pattern IngestDocumentJob/OcrService/DocumentDeleter/
        // DocumentIngestor/ReembedDocumentJob all already follow).
        // resolve() must never let this abort the whole extract() call for
        // the document — it must degrade to the same "no visual evidence"
        // red cell as any other unreadable source (R14).
        config(['kb.tabular_review.vision.enabled' => true]);
        Storage::fake('kb');
        Storage::disk('kb')->put('catalog/sku-5.jpg', 'JPEGBYTES');

        $doc = $this->doc([
            'source_path' => 'catalog/sku-5.jpg',
            'mime_type' => 'image/jpeg',
            'metadata' => ['prefix' => '../outside'],
        ]);

        Http::fake(['*' => Http::response(['error' => 'should_not_be_called'], 500)]);

        $resolver = $this->app->make(VisionColumnResolver::class);
        $result = $resolver->resolve($doc, 'What colour is the garment?', FormatType::TEXT, []);

        $this->assertNotNull($result);
        $this->assertNull($result['summary']);
        $this->assertSame(CellFlag::RED->value, $result['flag']);
        $this->assertStringContainsString('No visual evidence', $result['reasoning']);

        Http::assertNothingSent();
    }

    // ── Provider throws ──────────────────────────────────────────────

    public function test_provider_call_throwing_returns_a_red_cell_without_leaking_the_raw_exception(): void
    {
        config(['kb.tabular_review.vision.enabled' => true]);
        Storage::fake('kb');
        Storage::disk('kb')->put('catalog/sku-2.jpg', 'JPEGBYTES');

        $doc = $this->doc(['source_path' => 'catalog/sku-2.jpg', 'mime_type' => 'image/jpeg', 'metadata' => []]);

        Http::fake(['*' => Http::response(['error' => ['message' => 'super-secret-internal-hostname-leak']], 500)]);

        $resolver = $this->app->make(VisionColumnResolver::class);
        $result = $resolver->resolve($doc, 'What colour is the garment?', FormatType::TEXT, []);

        $this->assertNotNull($result);
        $this->assertNull($result['summary']);
        $this->assertSame(CellFlag::RED->value, $result['flag']);
        $this->assertStringContainsString('provider error', $result['reasoning']);
        // R14 / SEC-ERRLEAK-equivalent — the raw exception text never reaches
        // the persisted cell.
        $this->assertStringNotContainsString('super-secret-internal-hostname-leak', $result['reasoning']);
    }

    // ── Unparseable model output ─────────────────────────────────────

    public function test_unparseable_model_output_returns_a_red_cell(): void
    {
        config(['kb.tabular_review.vision.enabled' => true]);
        Storage::fake('kb');
        Storage::disk('kb')->put('catalog/sku-3.jpg', 'JPEGBYTES');

        $doc = $this->doc(['source_path' => 'catalog/sku-3.jpg', 'mime_type' => 'image/jpeg', 'metadata' => []]);

        Http::fake(['*' => Http::response($this->aiPayload('this is not json at all'), 200)]);

        $resolver = $this->app->make(VisionColumnResolver::class);
        $result = $resolver->resolve($doc, 'What colour is the garment?', FormatType::TEXT, []);

        $this->assertNotNull($result);
        $this->assertNull($result['summary']);
        $this->assertSame(CellFlag::RED->value, $result['flag']);
        $this->assertStringContainsString('did not return a usable result', $result['reasoning']);
    }

    // ── Happy path, format + enum_values honoured ────────────────────

    public function test_happy_path_honours_format_and_enum_values_in_the_prompt(): void
    {
        config(['kb.tabular_review.vision.enabled' => true]);
        Storage::fake('kb');
        Storage::disk('kb')->put('catalog/sku-4.jpg', 'JPEGBYTES');

        $doc = $this->doc(['source_path' => 'catalog/sku-4.jpg', 'mime_type' => 'image/jpeg', 'metadata' => []]);

        Http::fake(['*' => Http::response(
            $this->aiPayload('{"summary":"navy","flag":"green","reasoning":"Clearly navy in the photo."}'),
            200,
        )]);

        $resolver = $this->app->make(VisionColumnResolver::class);
        $result = $resolver->resolve(
            $doc,
            'What is the primary colour of the garment?',
            FormatType::ENUM,
            ['black', 'navy', 'red'],
        );

        $this->assertNotNull($result);
        $this->assertSame('navy', $result['summary']);
        $this->assertSame(CellFlag::GREEN->value, $result['flag']);
        $this->assertSame('Clearly navy in the photo.', $result['reasoning']);
        $this->assertCount(1, $result['citations']);

        Http::assertSent(function ($request): bool {
            $body = $request->body();

            // FormatType::ENUM::promptSuffix() renders the allowed values into
            // the system instructions sent to the provider (JSON-encoded, so
            // the `/` separators come back escaped as `\/`).
            return str_contains($body, 'black \/ navy \/ red');
        });
    }
}

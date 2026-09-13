<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Kb\Ocr\Drivers;

use App\Services\Kb\Ocr\Drivers\MistralOcrDriver;
use App\Services\Kb\Ocr\OcrDriverUnavailableException;
use App\Services\Kb\Ocr\OcrRequest;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * SEC-SSRF-001 — a base URL `parse_url()` cannot even parse is a loud
 * refusal before any egress, never a TypeError on a missing array offset.
 */
final class MistralOcrDriverEndpointTest extends TestCase
{
    public function test_an_unparseable_endpoint_url_is_refused_before_any_egress(): void
    {
        config(['kb.ocr.allow_remote' => true, 'kb.ocr.mistral.api_key' => 'k', 'kb.ocr.mistral.url' => 'http:///not-a-url', 'kb.ocr.mistral.allowed_hosts' => ['api.mistral.eu']]);
        Http::fake();

        try {
            app(MistralOcrDriver::class)->recognise(new OcrRequest('%PDF-1.4 x', 'application/pdf', 'a.pdf'));
            $this->fail('an unparseable endpoint must be refused');
        } catch (OcrDriverUnavailableException $e) {
            $this->assertStringContainsString('not a valid URL', $e->getMessage());
        }
        Http::assertNothingSent();
    }

    /**
     * SEC-EXTRESP-001 — a `pages: []` answer is an invalid response, never
     * a recorded run of zero pages the service would reuse and the
     * converter would persist as an empty document.
     */
    public function test_an_empty_page_list_is_an_invalid_response_not_an_empty_run(): void
    {
        config(['kb.ocr.allow_remote' => true, 'kb.ocr.mistral.api_key' => 'k', 'kb.ocr.mistral.url' => 'https://api.mistral.eu/v1/ocr', 'kb.ocr.mistral.allowed_hosts' => ['api.mistral.eu']]);
        Http::fake(['https://api.mistral.eu/*' => Http::response(['pages' => [], 'model' => 'mistral-ocr-latest'], 200)]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('returned no pages');
        app(MistralOcrDriver::class)->recognise(new OcrRequest((string) base64_decode(\App\Services\Kb\Ocr\Drivers\FakeOcrDriver::PNG_1X1, true), 'image/png', 'scan.png'));
    }

    /**
     * SEC-EXTRESP-001 — the provider's page numbering is validated: a
     * duplicate index (two figures would collide on one `fig-p-n` path), an
     * index outside the returned list (a bogus `## Page` heading) and a
     * non-integer index are each an invalid response, never a recorded run.
     */
    #[DataProvider('invalidPageIndexes')]
    public function test_an_invalid_page_index_is_an_invalid_response(array $indexes, string $message): void
    {
        config(['kb.ocr.allow_remote' => true, 'kb.ocr.mistral.api_key' => 'k', 'kb.ocr.mistral.url' => 'https://api.mistral.eu/v1/ocr', 'kb.ocr.mistral.allowed_hosts' => ['api.mistral.eu']]);
        Http::fake(['https://api.mistral.eu/*' => Http::response(['pages' => array_map(
            static fn ($index): array => ['index' => $index, 'markdown' => 'text'],
            $indexes,
        )], 200)]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($message);
        app(MistralOcrDriver::class)->recognise(new OcrRequest((string) base64_decode(\App\Services\Kb\Ocr\Drivers\FakeOcrDriver::PNG_1X1, true), 'image/png', 'scan.png'));
    }

    /** @return array<string, array{0: list<mixed>, 1: string}> */
    public static function invalidPageIndexes(): array
    {
        return [
            'duplicate' => [[0, 0], 'returned page 1 twice'],
            'out of range' => [[0, 7], 'invalid page index (7)'],
            'negative' => [[-1], 'invalid page index (-1)'],
            'not an integer' => [['1'], "invalid page index ('1')"],
        ];
    }

    /**
     * A source image goes to the provider as is: it is measured against the
     * same pixel box the rasterising drivers apply to a rendered page BEFORE
     * the request is built — an image over it never leaves.
     */
    public function test_a_source_image_over_the_pixel_box_is_refused_before_any_egress(): void
    {
        config(['kb.ocr.allow_remote' => true, 'kb.ocr.mistral.api_key' => 'k', 'kb.ocr.mistral.url' => 'https://api.mistral.eu/v1/ocr', 'kb.ocr.mistral.allowed_hosts' => ['api.mistral.eu'], 'kb.ocr.raster.max_page_px' => 500]);
        // One fake for the whole test (stubs accumulate across Http::fake() calls).
        Http::fake(['https://api.mistral.eu/*' => Http::response(['pages' => [['index' => 0, 'markdown' => 'ok']]], 200)]);
        // PNG signature + IHDR declaring 900×10 px: tiny bytes, over the box.
        $png = "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.pack('NN', 900, 10)."\x08\x02\x00\x00\x00".pack('N', 0);

        try {
            app(MistralOcrDriver::class)->recognise(new OcrRequest($png, 'image/png', 'wide.png'));
            $this->fail('an image over the pixel box must be refused');
        } catch (\App\Services\Kb\Ocr\OcrLimitExceededException $e) {
            $this->assertSame('rendered_page_too_large', $e->reason);
            $this->assertStringContainsString('900×10 px', $e->getMessage());
        }
        Http::assertNothingSent();

        // Inside the box: the request is built and sent.
        config(['kb.ocr.raster.max_page_px' => 1000]);
        $this->assertCount(1, app(MistralOcrDriver::class)->recognise(new OcrRequest($png, 'image/png', 'wide.png'))->pages);
        Http::assertSentCount(1);
    }

    /** The run's aggregate figure budget applies to a remote response too: a figure past it is dropped and its link becomes text. */
    public function test_figures_past_the_run_budget_are_dropped(): void
    {
        config(['kb.ocr.allow_remote' => true, 'kb.ocr.mistral.api_key' => 'k', 'kb.ocr.mistral.url' => 'https://api.mistral.eu/v1/ocr', 'kb.ocr.mistral.allowed_hosts' => ['api.mistral.eu'], 'kb.ocr.max_figures_per_run' => 1]);
        $jpeg = "\xFF\xD8\xFF\xE0".str_repeat("\x00", 16);
        Http::fake(['https://api.mistral.eu/*' => Http::response(['pages' => [[
            'index' => 0,
            'markdown' => "![a](img-0.jpeg)\n\n![b](img-1.jpeg)",
            'images' => [
                ['id' => 'img-0.jpeg', 'image_base64' => base64_encode($jpeg)],
                ['id' => 'img-1.jpeg', 'image_base64' => base64_encode($jpeg)],
            ],
        ]]], 200)]);

        $result = app(MistralOcrDriver::class)->recognise(new OcrRequest((string) base64_decode(\App\Services\Kb\Ocr\Drivers\FakeOcrDriver::PNG_1X1, true), 'image/png', 'scan.png'));

        $this->assertCount(1, $result->pages[0]->figures);
        $this->assertStringContainsString('(images/fig-1-1.jpg)', $result->pages[0]->markdown);
        $this->assertStringNotContainsString('img-1.jpeg', $result->pages[0]->markdown);
        $this->assertStringContainsString('*[Figure: b]*', $result->pages[0]->markdown);
    }

    /** Pages come back in page order whatever order the provider listed them in. */
    public function test_pages_are_ordered_by_their_index(): void
    {
        config(['kb.ocr.allow_remote' => true, 'kb.ocr.mistral.api_key' => 'k', 'kb.ocr.mistral.url' => 'https://api.mistral.eu/v1/ocr', 'kb.ocr.mistral.allowed_hosts' => ['api.mistral.eu']]);
        Http::fake(['https://api.mistral.eu/*' => Http::response(['pages' => [
            ['index' => 1, 'markdown' => 'second'],
            ['index' => 0, 'markdown' => 'first'],
        ]], 200)]);

        $result = app(MistralOcrDriver::class)->recognise(new OcrRequest('%PDF-1.4 x', 'application/pdf', 'a.pdf'));

        $this->assertSame([1, 2], array_map(static fn ($p) => $p->number, $result->pages));
        $this->assertSame('first', $result->pages[0]->markdown);
    }

    /**
     * SEC-EXTRESP-001 — a returned figure is stored under the format its
     * BYTES are (the API returns JPEG as readily as PNG), a blob that is no
     * raster we serve is dropped, and only the stored figures may be cited:
     * any other image link in the provider's Markdown becomes text.
     */
    public function test_figures_take_the_format_of_their_bytes_and_foreign_image_links_become_text(): void
    {
        config(['kb.ocr.allow_remote' => true, 'kb.ocr.mistral.api_key' => 'k', 'kb.ocr.mistral.url' => 'https://api.mistral.eu/v1/ocr', 'kb.ocr.mistral.allowed_hosts' => ['api.mistral.eu']]);
        $jpeg = "\xFF\xD8\xFF\xE0".str_repeat("\x00", 16);
        Http::fake(['https://api.mistral.eu/*' => Http::response(['pages' => [[
            'index' => 0,
            'markdown' => "Title\n\n![img-0.jpeg](img-0.jpeg)\n\n![not returned](img-9.png)\n\n![chart](https://evil.example/track.png)",
            'images' => [
                ['id' => 'img-0.jpeg', 'image_base64' => 'data:image/jpeg;base64,'.base64_encode($jpeg)],
                ['id' => 'blob', 'image_base64' => base64_encode('not a raster at all')],
            ],
        ]]], 200)]);

        $result = app(MistralOcrDriver::class)->recognise(new OcrRequest((string) base64_decode(\App\Services\Kb\Ocr\Drivers\FakeOcrDriver::PNG_1X1, true), 'image/png', 'scan.png'));

        $this->assertCount(1, $result->pages);
        $figures = $result->pages[0]->figures;
        $this->assertCount(1, $figures, 'a blob that is no raster is dropped');
        $this->assertSame('jpg', $figures[0]->extension);
        $this->assertSame('fig-1-1.jpg', $figures[0]->fileName());
        $markdown = $result->pages[0]->markdown;
        $this->assertStringContainsString('(images/fig-1-1.jpg)', $markdown);
        $this->assertStringNotContainsString('img-9.png', $markdown);
        $this->assertStringNotContainsString('evil.example', $markdown);
        $this->assertStringContainsString('*[Figure: chart]*', $markdown);
    }

    public function test_the_preflight_reports_a_bad_endpoint_as_unavailable(): void
    {
        config(['kb.ocr.allow_remote' => true, 'kb.ocr.mistral.api_key' => 'k', 'kb.ocr.mistral.allowed_hosts' => ['api.mistral.eu']]);

        config(['kb.ocr.mistral.url' => 'https://api.mistral.eu/v1/ocr']);
        $this->assertTrue(app(MistralOcrDriver::class)->isAvailable());
        config(['kb.ocr.mistral.url' => 'http://api.mistral.eu/v1/ocr']);
        $this->assertFalse(app(MistralOcrDriver::class)->isAvailable(), 'not https');
        config(['kb.ocr.mistral.url' => 'https://evil.example/v1/ocr']);
        $this->assertFalse(app(MistralOcrDriver::class)->isAvailable(), 'not allow-listed');
        config(['kb.ocr.mistral.url' => 'http:///not-a-url']);
        $this->assertFalse(app(MistralOcrDriver::class)->isAvailable(), 'unparseable');
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Kb\Ocr\Drivers;

use App\Services\Kb\Ocr\Drivers\MistralOcrDriver;
use App\Services\Kb\Ocr\OcrDriverUnavailableException;
use App\Services\Kb\Ocr\OcrRequest;
use Illuminate\Support\Facades\Http;
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

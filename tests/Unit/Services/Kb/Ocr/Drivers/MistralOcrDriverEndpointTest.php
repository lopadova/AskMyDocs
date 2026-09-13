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

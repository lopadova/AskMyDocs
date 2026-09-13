<?php

declare(strict_types=1);

namespace App\Services\Kb\Ocr\Drivers;

use App\Services\Kb\Ocr\OcrDriver;
use App\Services\Kb\Ocr\OcrDriverUnavailableException;
use App\Services\Kb\Ocr\OcrFigure;
use App\Services\Kb\Ocr\OcrMarkdown;
use App\Services\Kb\Ocr\OcrMeteringMode;
use App\Services\Kb\Ocr\OcrPage;
use App\Services\Kb\Ocr\OcrRequest;
use App\Services\Kb\Ocr\OcrResult;
use App\Support\Kb\FileTypeSniffer;
use App\Support\Kb\SourceType;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Mistral OCR (API, EU-hosted). Strongest on tables and complex layouts.
 *
 * One request per document (`POST /v1/ocr` with a base64 data URL); the
 * response carries one entry per page with Markdown and, when asked,
 * base64 images referenced from the Markdown. Exercised through recorded
 * fixtures in CI (never live — see RUNBOOK-live-fixture-recording.md).
 *
 * The endpoint is operator configuration, never request input (SEC-SSRF-001);
 * the response is validated for status, JSON shape and page list before use
 * (SEC-EXTRESP-001). The key travels in the Authorization header, never in
 * the query string (rule-logging-security).
 */
final class MistralOcrDriver implements OcrDriver
{
    public function name(): string
    {
        return 'mistral-ocr';
    }

    public function isAvailable(): bool
    {
        return $this->unavailableReason() === null;
    }

    public function unavailableReason(): ?string
    {
        if (trim((string) config('kb.ocr.mistral.api_key', '')) === '') {
            return 'Mistral OCR API key missing — set KB_OCR_MISTRAL_API_KEY (or MISTRAL_API_KEY).';
        }
        // The preflight (estimate, re-run) must say what recognise() will do:
        // a malformed, non-https or non-allow-listed endpoint is "cannot run
        // here", never a run queued to fail deterministically in the worker.
        try {
            $this->assertAllowedEndpoint((string) config('kb.ocr.mistral.url', ''));
        } catch (OcrDriverUnavailableException $e) {
            return $e->getMessage();
        }

        return null;
    }

    public function isRemote(): bool
    {
        return true;
    }

    public function fingerprint(): string
    {
        return sprintf('model=%s;url=%s', (string) config('kb.ocr.mistral.model', 'mistral-ocr-latest'), (string) config('kb.ocr.mistral.url', ''));
    }

    /**
     * SEC-SSRF-001 / SEC-LLM-001 gate 2 — the document bytes go only to a
     * host in the exact allow-list (`kb.ocr.mistral.allowed_hosts`), over
     * https. A misconfigured base URL is a loud refusal before any egress.
     */
    private function assertAllowedEndpoint(string $url): void
    {
        $parts = parse_url($url);
        if ($parts === false) {
            throw new OcrDriverUnavailableException('Mistral OCR endpoint URL (kb.ocr.mistral.url) is not a valid URL; refusing to send the document.');
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $allowed = array_values(array_filter(array_map(
            static fn ($h): string => strtolower(trim((string) $h)),
            (array) config('kb.ocr.mistral.allowed_hosts', []),
        )));

        $port = (int) ($parts['port'] ?? 443);
        if ($scheme !== 'https' || $port !== 443 || $host === '' || ! in_array($host, $allowed, true)) {
            throw new OcrDriverUnavailableException(sprintf(
                'Mistral OCR endpoint host "%s" is not in kb.ocr.mistral.allowed_hosts (%s); refusing to send the document.',
                $host,
                implode(', ', $allowed),
            ));
        }
    }

    /** One HTTP call for the whole document under the client timeout. */
    public function maxDurationSeconds(int $pages): int
    {
        return max(1, (int) config('kb.ocr.mistral.timeout', 120));
    }

    /** Remote, whole-file: never receives an unverifiable document. */
    public function boundsWorkWithoutPageCount(): bool
    {
        return false;
    }

    /**
     * Read the response body in bounded chunks: the read stops — and the
     * stream is closed — the moment the cap is exceeded, so the worker never
     * holds more than `max_response_bytes` (+ one chunk) of an untrusted answer.
     *
     * @throws RuntimeException when the body exceeds `$maxBody`
     */
    private function readBounded(\Psr\Http\Message\StreamInterface $stream, int $maxBody): string
    {
        $buffer = '';
        try {
            while (! $stream->eof()) {
                $chunk = $stream->read(65536);
                if ($chunk === '') {
                    break;
                }
                $buffer .= $chunk;
                if (strlen($buffer) > $maxBody) {
                    throw new RuntimeException('Mistral OCR response exceeds the configured size limit.');
                }
            }
        } finally {
            $stream->close();
        }

        return $buffer;
    }

    /** An image goes up as ONE `image_url` data URL; the API returns one page for it. */
    public function acceptsMultiFrameImages(): bool
    {
        return false;
    }

    public function meteringMode(): OcrMeteringMode
    {
        return OcrMeteringMode::PerPage;
    }

    public function recognise(OcrRequest $request): OcrResult
    {
        $reason = $this->unavailableReason();
        if ($reason !== null) {
            throw new OcrDriverUnavailableException($reason);
        }

        // The label is the family MIME (`image/png` for every raster): the
        // data URL must name what the bytes actually are or the API rejects
        // or mis-decodes a JPEG/TIFF/WebP sent as PNG.
        $mime = $request->effectiveMimeType();
        $dataUrl = 'data:'.$mime.';base64,'.base64_encode($request->bytes);
        $document = $request->isPdf()
            ? ['type' => 'document_url', 'document_url' => $dataUrl]
            : ['type' => 'image_url', 'image_url' => $dataUrl];

        $url = (string) config('kb.ocr.mistral.url', 'https://api.mistral.eu/v1/ocr');
        $this->assertAllowedEndpoint($url);

        // The host was allow-listed above; a redirect would let the endpoint
        // send the document bytes somewhere that was not (SEC-SSRF-001), so
        // the client never follows one — a 3xx is a failed call, not a hop.
        // `stream => true`: the body is NOT buffered by the client — it is
        // read below in bounded chunks and abandoned the moment it exceeds
        // the cap, so an allow-listed endpoint cannot exhaust the worker's
        // memory before the size check runs (SEC-EXTRESP-001).
        $response = Http::withToken((string) config('kb.ocr.mistral.api_key'))
            ->acceptJson()
            ->withoutRedirecting()
            ->withOptions(['stream' => true])
            ->timeout((int) config('kb.ocr.mistral.timeout', 120))
            ->post($url, [
                'model' => (string) config('kb.ocr.mistral.model', 'mistral-ocr-latest'),
                'document' => $document,
                'include_image_base64' => (bool) config('kb.ocr.figures.enabled', true),
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(sprintf(
                'Mistral OCR returned HTTP %d for "%s".',
                $response->status(),
                $request->filename,
            ));
        }

        // SEC-EXTRESP-001 — validate content type and bound the body before
        // decoding it; a hosted API answer is untrusted input.
        $contentType = strtolower((string) $response->header('Content-Type'));
        if (! str_starts_with($contentType, 'application/json')) {
            throw new RuntimeException('Mistral OCR response is not JSON (content-type "'.mb_substr($contentType, 0, 60).'").');
        }
        $maxBody = max(1, (int) config('kb.ocr.mistral.max_response_bytes', 64 * 1024 * 1024));
        $payload = json_decode($this->readBounded($response->toPsrResponse()->getBody(), $maxBody), true);
        if (! is_array($payload) || ! isset($payload['pages']) || ! is_array($payload['pages'])) {
            throw new RuntimeException('Mistral OCR response has no `pages` list.');
        }
        $maxFigureBytes = max(1, (int) config('kb.ocr.max_figure_bytes', 10 * 1024 * 1024));

        $pages = [];
        foreach (array_values($payload['pages']) as $i => $page) {
            if (! is_array($page)) {
                continue;
            }
            $number = (int) ($page['index'] ?? $i) + 1;
            $markdown = (string) ($page['markdown'] ?? '');
            $figures = [];
            foreach (array_values((array) ($page['images'] ?? [])) as $n => $image) {
                if (! is_array($image)) {
                    continue;
                }
                $b64 = (string) ($image['image_base64'] ?? '');
                $b64 = (string) preg_replace('#^data:[^;]+;base64,#', '', $b64);
                $bytes = base64_decode($b64, true);
                if ($bytes === false || $bytes === '' || strlen($bytes) > $maxFigureBytes) {
                    continue;
                }
                // SEC-EXTRESP-001 — the format is what the decoded bytes ARE
                // (the API returns JPEG as readily as PNG); a blob that is no
                // raster we serve is dropped, never stored under a `.png` name
                // a reader would fail to decode.
                $figureMime = FileTypeSniffer::imageMimeOf(substr($bytes, 0, 16));
                if ($figureMime === null) {
                    continue;
                }
                $figure = new OcrFigure($number, $n + 1, $bytes, SourceType::imageExtensionFromMime($figureMime), (string) ($image['id'] ?? ''));
                $figures[] = $figure;
                if ($figure->placeholder !== null && $figure->placeholder !== '') {
                    $markdown = str_replace(
                        '('.$figure->placeholder.')',
                        '(images/'.$figure->fileName().')',
                        $markdown,
                    );
                }
            }
            // Only the figures above may be cited: any other image link the
            // provider emitted (an unmatched placeholder, an external URL)
            // becomes text, never a reference the renderer would load.
            $markdown = OcrMarkdown::stripForeignImageLinks($markdown, array_map(static fn (OcrFigure $f): string => $f->fileName(), $figures));
            $pages[] = new OcrPage(number: $number, markdown: trim($markdown), confidence: null, figures: $figures);
        }
        // SEC-EXTRESP-001 — an empty (or all-malformed) page list is an
        // invalid answer, never a recorded run of zero pages the service
        // would reuse and the converter would persist as an empty document.
        if ($pages === []) {
            throw new RuntimeException(sprintf('Mistral OCR returned no pages for "%s".', $request->filename));
        }

        return new OcrResult(
            driver: $this->name(),
            pages: $pages,
            meta: ['model' => (string) ($payload['model'] ?? config('kb.ocr.mistral.model'))],
        );
    }
}

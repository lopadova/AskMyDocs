<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Services\Kb\Ocr\OcrService;
use App\Jobs\IngestDocumentJob;
use App\Support\Kb\FileTypeSniffer;
use App\Support\Kb\SourceType;
use App\Support\KbPath;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Remote ingestion endpoint.
 *
 * Accepts one or more documents (markdown / text / pdf / docx) in a single
 * JSON request, persists their bytes to the configured KB disk, and enqueues
 * one IngestDocumentJob per document. Used by the shipped GitHub composite
 * action `ingest-to-askmydocs` and any other client that can POST JSON.
 *
 * Per-document fields (T1.8):
 *  - `mime_type` (optional, default `text/markdown`) — drives Converter +
 *    Chunker resolution via `config/kb-pipeline.php`. Unsupported MIMEs
 *    return 422.
 *  - `content` — the document bytes. For text-based MIMEs (markdown, text)
 *    this is the raw text. For binary MIMEs (pdf, docx) this MUST be a
 *    base64-encoded string (the validator rejects payloads that don't
 *    decode cleanly).
 *
 * Auth: Sanctum bearer token (same as /api/kb/chat).
 */
class KbIngestController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'documents' => ['required', 'array', 'min:1', 'max:100'],
            // `nullable` lets callers omit project_key entirely (default
            // applied below); `filled` rejects explicit empty strings so
            // a blank tenant key never lands as the project_key value
            // (would be indistinguishable from "default" but with worse
            // observability).
            'documents.*.project_key' => ['nullable', 'filled', 'string', 'max:120'],
            'documents.*.source_path' => ['required', 'string', 'max:500'],
            'documents.*.title' => ['nullable', 'string', 'max:255'],
            'documents.*.mime_type' => ['nullable', 'string', 'max:200'],
            // Body cap is generous (up to 7,000,000 characters; for base64
            // payloads that's roughly 5.25 MB of raw bytes) to accommodate
            // multi-page PDFs / DOCX. Still subject to PHP's `post_max_size`
            // and Laravel's request body limit.
            'documents.*.content' => ['required', 'string', 'max:7000000'],
            'documents.*.metadata' => ['nullable', 'array'],
        ]);

        $disk = (string) config('kb.sources.disk', 'kb');
        $prefix = (string) config('kb.sources.path_prefix', '');
        $defaultProject = (string) config('kb.ingest.default_project', 'default');

        $storage = Storage::disk($disk);

        // H7 — PHASE 1: validate + decode EVERY document before writing a
        // single byte. A bad mime / non-base64 / traversal path on document
        // N now rejects the whole batch with 422 and nothing has been
        // written or queued — no partial-batch inconsistency.
        $prepared = [];
        foreach ($validated['documents'] as $doc) {
            $prepared[] = $this->prepareDocument($doc, $defaultProject, $prefix);
        }

        // H7 — PHASE 2: write + dispatch. A disk write can still fail here
        // (I/O, quota) AFTER earlier documents were queued. Instead of
        // aborting with a bare 500 that hides what already happened, record
        // a per-document status and report the full picture so the caller
        // can retry only the failures (DocumentIngestor is idempotent).
        $results = [];
        $anyFailed = false;
        foreach ($prepared as $item) {
            // Decode lazily, one document at a time (bounded memory). base64
            // validity was already proven in PHASE 1, so this won't fail.
            $bytes = $item['is_binary']
                ? (string) base64_decode($item['content'], true)
                : $item['content'];

            // The kb disk is configured with throw => false, so put() returns
            // false on failure instead of raising.
            $written = $storage->put($item['stored_path'], $bytes) !== false;

            if (! $written) {
                $anyFailed = true;
                $results[] = [
                    'project_key' => $item['project_key'],
                    'source_path' => $item['source_path'],
                    'source_type' => $item['source_type'],
                    'status' => 'failed',
                    'error' => 'Failed to write document to KB disk.',
                ];

                continue;
            }

            // PR #115 review iteration 1 — capture TenantContext at
            // dispatch time so the queue worker re-binds the right
            // tenant before any tenant-aware Eloquent query runs (R30/R31).
            IngestDocumentJob::dispatchForCurrentTenant(
                projectKey: $item['project_key'],
                relativePath: $item['source_path'],
                disk: $disk,
                title: $item['title'],
                metadata: $item['metadata'],
                mimeType: $item['mime_type'],
            );

            $results[] = [
                'project_key' => $item['project_key'],
                'source_path' => $item['source_path'],
                'source_type' => $item['source_type'],
                'status' => 'queued',
            ];
        }

        $queued = count(array_filter($results, static fn (array $r): bool => $r['status'] === 'queued'));

        return response()->json([
            'queued' => $queued,
            'failed' => count($results) - $queued,
            'documents' => $results,
            // H7 — surface the partial-failure state loudly (R14): a caller
            // must be able to tell "all queued" (202) from "some writes
            // failed" (207 Multi-Status).
        ], $anyFailed ? 207 : 202);
    }

    /**
     * Validate + decode a single document into a write-ready descriptor.
     * Throws ValidationException (422) on any problem so PHASE 1 can reject
     * the whole batch before any disk write happens (H7).
     *
     * @param  array<string, mixed>  $doc
     * @return array{project_key:string, source_path:string, source_type:string, mime_type:string, content:string, is_binary:bool, stored_path:string, title:?string, metadata:array<string,mixed>}
     */
    private function prepareDocument(array $doc, string $defaultProject, string $prefix): array
    {
        $projectKey = (string) ($doc['project_key'] ?? $defaultProject);

        try {
            $sourcePath = KbPath::normalize((string) $doc['source_path']);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['documents' => [$e->getMessage()]]);
        }
        // v8.36 / ADR 0029 §6 — the converters' own output (`{source}.ocr/`,
        // `.artifacts/`) is never a source: accepting such a path would let a
        // client overwrite a recorded run or an artifact and re-ingest it.
        if (KbPath::isGeneratedAsset($sourcePath)) {
            throw ValidationException::withMessages([
                'documents' => [sprintf('source_path "%s" is inside a generated-asset directory (.ocr/ or .artifacts/) and cannot be ingested as a source.', $sourcePath)],
            ]);
        }

        $mimeType = trim((string) ($doc['mime_type'] ?? 'text/markdown'));
        $sourceType = SourceType::fromMime($mimeType);
        // v8.36 / ADR 0029 — images are accepted only when OCR is on (R43):
        // with the flag off an image is refused with the SAME 422 as before,
        // and the "Supported:" list does not mention it.
        $ocrEnabled = (bool) config('kb.ocr.enabled', false);
        if ($sourceType === SourceType::UNKNOWN || ($sourceType === SourceType::IMAGE && ! $ocrEnabled)) {
            throw ValidationException::withMessages([
                'documents' => [sprintf(
                    'Unsupported mime_type "%s" for source_path "%s". Supported: %s.',
                    $mimeType,
                    $sourcePath,
                    implode(', ', SourceType::supportedMimes($ocrEnabled)),
                )],
            ]);
        }

        // Binary MIMEs arrive base64-encoded. VALIDATE decodability here
        // (decode + discard) so a non-base64 payload still 422s the whole
        // batch in PHASE 1 — but do NOT retain the decoded bytes. Holding a
        // decoded copy of every document (up to max:100 × ~5 MB) alongside
        // the raw request payload risked OOM on large batches (Copilot
        // review). PHASE 2 re-decodes one document at a time at write time,
        // so peak memory stays bounded to a single decoded document.
        $isBinary = $sourceType->isBinary();
        $decoded = $isBinary ? base64_decode((string) $doc['content'], true) : null;
        if ($isBinary && $decoded === false) {
            throw ValidationException::withMessages([
                'documents' => [sprintf(
                    'documents.*.content for binary mime_type "%s" must be valid base64 (source_path: %s).',
                    $mimeType,
                    $sourcePath,
                )],
            ]);
        }
        // v8.36 / ADR 0029 §2 — an image is verified from its bytes, exactly as
        // the multipart upload does: the declared MIME is a label, the magic
        // bytes are the fact. Anything that is not a PNG/JPEG/TIFF/WebP is
        // refused, and the MIME that travels is the one the bytes carry.
        if ($sourceType === SourceType::IMAGE) {
            $sniffed = FileTypeSniffer::imageMimeOf(substr((string) $decoded, 0, 16));
            if ($sniffed === null) {
                throw ValidationException::withMessages([
                    'documents' => [sprintf(
                        'documents.*.content declared as %s for source_path "%s" is not a PNG, JPEG, TIFF or WebP image.',
                        $mimeType,
                        $sourcePath,
                    )],
                ]);
            }
            $mimeType = $sniffed;
        }
        unset($decoded); // PHASE 2 re-decodes one document at a time at write time

        return [
            'project_key' => $projectKey,
            'source_path' => $sourcePath,
            'source_type' => $sourceType->value,
            'mime_type' => $mimeType,
            // Raw content + a flag; PHASE 2 decodes binaries lazily.
            'content' => (string) $doc['content'],
            'is_binary' => $isBinary,
            // L1 — normalise the prefixed stored path through KbPath instead
            // of a hand-rolled ltrim, so a trailing-slash prefix can't yield
            // a double-slash path that diverges from the delete flow.
            'stored_path' => $prefix === ''
                ? $sourcePath
                : KbPath::normalize($prefix.'/'.$sourcePath),
            'title' => $doc['title'] ?? null,
            // v8.36 — `ocr.force` / `ocr.rerun_lock` / `dry_run` are host-only
            // controls (a forced run is billed): never accepted from a client.
            'metadata' => OcrService::stripTrustedOnlyKeys(is_array($doc['metadata'] ?? null) ? $doc['metadata'] : []),
        ];
    }
}

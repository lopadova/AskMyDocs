<?php

namespace App\Http\Controllers\Api;

use App\Jobs\IngestDocumentJob;
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
            'documents.*.project_key' => ['nullable', 'string', 'max:120'],
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
        $results = [];

        foreach ($validated['documents'] as $doc) {
            $projectKey = (string) ($doc['project_key'] ?? $defaultProject);
            try {
                $sourcePath = KbPath::normalize((string) $doc['source_path']);
            } catch (\InvalidArgumentException $e) {
                throw ValidationException::withMessages([
                    'documents' => [$e->getMessage()],
                ]);
            }

            $mimeType = trim((string) ($doc['mime_type'] ?? 'text/markdown'));
            $sourceType = SourceType::fromMime($mimeType);
            if ($sourceType === SourceType::UNKNOWN) {
                throw ValidationException::withMessages([
                    'documents' => [sprintf(
                        'Unsupported mime_type "%s" for source_path "%s". Supported: %s.',
                        $mimeType,
                        $sourcePath,
                        // Use the SourceType-owned authoritative list so
                        // ALIASES like text/x-markdown are advertised too —
                        // SourceType::cases()->toMime() would only emit
                        // canonical forms and silently exclude accepted
                        // aliases the validator actually recognises.
                        implode(', ', SourceType::supportedMimes()),
                    )],
                ]);
            }

            // Binary MIMEs land here as base64; decode-or-422 before writing
            // the bytes to disk. strict=true rejects whitespace + non-b64
            // chars so we never persist garbage that the converter would
            // then choke on.
            $bytes = (string) $doc['content'];
            if ($sourceType->isBinary()) {
                $decoded = base64_decode($bytes, true);
                if ($decoded === false) {
                    throw ValidationException::withMessages([
                        'documents' => [sprintf(
                            'documents.*.content for binary mime_type "%s" must be valid base64 (source_path: %s).',
                            $mimeType,
                            $sourcePath,
                        )],
                    ]);
                }
                $bytes = $decoded;
            }

            $storedPath = ltrim($prefix.'/'.$sourcePath, '/');

            // The kb disk is configured with throw => false, so put() returns
            // false on failure instead of raising. Check explicitly so we
            // don't enqueue a job that will immediately fail with "not found".
            if ($storage->put($storedPath, $bytes) === false) {
                return response()->json([
                    'error' => 'Failed to write document to KB disk.',
                    'source_path' => $sourcePath,
                ], 500);
            }

            IngestDocumentJob::dispatch(
                projectKey: $projectKey,
                relativePath: $sourcePath,
                disk: $disk,
                title: $doc['title'] ?? null,
                metadata: is_array($doc['metadata'] ?? null) ? $doc['metadata'] : [],
                mimeType: $mimeType,
            );

            $results[] = [
                'project_key' => $projectKey,
                'source_path' => $sourcePath,
                'source_type' => $sourceType->value,
                'status' => 'queued',
            ];
        }

        return response()->json([
            'queued' => count($results),
            'documents' => $results,
        ], 202);
    }
}

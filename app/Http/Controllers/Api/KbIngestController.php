<?php

namespace App\Http\Controllers\Api;

use App\Jobs\IngestDocumentJob;
use App\Support\KbPath;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Remote ingestion endpoint.
 *
 * Accepts one or more markdown documents in a single JSON request,
 * persists their content to the configured KB disk, and enqueues one
 * IngestDocumentJob per document. Used by the shipped GitHub composite
 * action `ingest-to-askmydocs` and any other client that can POST JSON.
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
            'documents.*.content' => ['required', 'string', 'max:5000000'],
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
            $storedPath = ltrim($prefix.'/'.$sourcePath, '/');

            // The kb disk is configured with throw => false, so put() returns
            // false on failure instead of raising. Check explicitly so we
            // don't enqueue a job that will immediately fail with "not found".
            if ($storage->put($storedPath, (string) $doc['content']) === false) {
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
            );

            $results[] = [
                'project_key' => $projectKey,
                'source_path' => $sourcePath,
                'status' => 'queued',
            ];
        }

        return response()->json([
            'queued' => count($results),
            'documents' => $results,
        ], 202);
    }
}

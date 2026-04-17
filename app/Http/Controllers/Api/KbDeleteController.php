<?php

namespace App\Http\Controllers\Api;

use App\Services\Kb\DocumentDeleter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;

/**
 * Remote deletion endpoint.
 *
 * Accepts one or more {project_key, source_path} pairs and removes the
 * matching knowledge documents. Behaviour follows `kb.deletion.soft_delete`
 * unless the caller sets `force: true`, which always hard-deletes.
 *
 * Used by the shipped GitHub composite action `ingest-to-askmydocs` when
 * it detects files removed by the current push.
 *
 * Auth: Sanctum bearer token (same as /api/kb/chat and /api/kb/ingest).
 */
class KbDeleteController extends Controller
{
    public function __invoke(Request $request, DocumentDeleter $deleter): JsonResponse
    {
        $validated = $request->validate([
            'documents' => ['required', 'array', 'min:1', 'max:100'],
            'documents.*.project_key' => ['nullable', 'string', 'max:120'],
            'documents.*.source_path' => ['required', 'string', 'max:500'],
            'force' => ['nullable', 'boolean'],
        ]);

        $defaultProject = (string) config('kb.ingest.default_project', 'default');
        $force = array_key_exists('force', $validated) && $validated['force'] !== null
            ? (bool) $validated['force']
            : null;

        $deleted = [];
        $missing = [];

        foreach ($validated['documents'] as $doc) {
            $projectKey = (string) ($doc['project_key'] ?? $defaultProject);
            $sourcePath = $this->normalizePath((string) $doc['source_path']);

            $result = $deleter->deleteByPath($projectKey, $sourcePath, $force);

            if ($result === null) {
                $missing[] = [
                    'project_key' => $projectKey,
                    'source_path' => $sourcePath,
                    'status' => 'not_found',
                ];
                continue;
            }

            $deleted[] = [
                'project_key' => $result['project_key'],
                'source_path' => $result['source_path'],
                'document_id' => $result['document_id'],
                'mode' => $result['mode'],
                'file_deleted' => $result['file_deleted'],
                'status' => 'deleted',
            ];
        }

        return response()->json([
            'deleted' => count($deleted),
            'missing' => count($missing),
            'documents' => array_merge($deleted, $missing),
        ], 200);
    }

    private function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $path = preg_replace('#/+#', '/', $path) ?? $path;
        $path = trim($path, '/');

        if ($path === '') {
            throw ValidationException::withMessages([
                'documents' => ['Each source_path must be a non-empty relative path.'],
            ]);
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '.' || $segment === '..') {
                throw ValidationException::withMessages([
                    'documents' => ['Each source_path must be a relative path without "." or ".." segments.'],
                ]);
            }
        }

        return $path;
    }
}

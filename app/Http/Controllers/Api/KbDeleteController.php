<?php

namespace App\Http\Controllers\Api;

use App\Flow\Definitions\DeleteDocumentFlow;
use App\Support\KbPath;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use Padosoft\LaravelFlow\Facades\Flow;
use Padosoft\LaravelFlow\FlowExecutionOptions;
use Padosoft\LaravelFlow\FlowRun;

/**
 * Remote deletion endpoint.
 *
 * Accepts one or more {project_key, source_path} pairs and removes the
 * matching knowledge documents via the {@see DeleteDocumentFlow} saga.
 * Behaviour follows `kb.deletion.soft_delete` unless the caller sets
 * `force: true`, which always hard-deletes.
 *
 * Used by the shipped GitHub composite action `ingest-to-askmydocs` when
 * it detects files removed by the current push.
 *
 * v4.2/W2 PR #116 — refactored onto DeleteDocumentFlow. Response shape is
 * preserved (deleted/missing counters + per-doc status). Each call site
 * dispatches one Flow per document so individual run failures don't
 * cascade across the batch.
 *
 * Auth: Sanctum bearer token (same as /api/kb/chat and /api/kb/ingest).
 */
class KbDeleteController extends Controller
{
    public function __invoke(Request $request, TenantContext $tenants): JsonResponse
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
            : ! (bool) config('kb.deletion.soft_delete', true);

        $tenantId = $tenants->current();
        $deleted = [];
        $missing = [];

        foreach ($validated['documents'] as $doc) {
            $projectKey = (string) ($doc['project_key'] ?? $defaultProject);
            try {
                $sourcePath = KbPath::normalize((string) $doc['source_path']);
            } catch (\InvalidArgumentException $e) {
                throw ValidationException::withMessages([
                    'documents' => [$e->getMessage()],
                ]);
            }

            $run = Flow::execute(
                DeleteDocumentFlow::NAME,
                [
                    'tenant_id' => $tenantId,
                    'project_key' => $projectKey,
                    'source_path' => $sourcePath,
                    'force' => $force,
                    'keep_file' => false,
                ],
                FlowExecutionOptions::make(
                    correlationId: $tenantId,
                ),
            );

            if ($run->status !== FlowRun::STATUS_SUCCEEDED) {
                // A flow-level failure surfaces as a per-document missing
                // entry with a `flow_failed` status so the batch keeps
                // making progress and the operator sees the failure.
                $missing[] = [
                    'project_key' => $projectKey,
                    'source_path' => $sourcePath,
                    'status' => 'flow_failed',
                    'flow_run_id' => $run->id,
                ];
                continue;
            }

            $loadOutput = $run->stepResults['load-document']?->output ?? [];
            if (! ($loadOutput['found'] ?? false)) {
                $missing[] = [
                    'project_key' => $projectKey,
                    'source_path' => $sourcePath,
                    'status' => 'not_found',
                ];
                continue;
            }

            $hardOutput = $run->stepResults['hard-delete-rows']?->output ?? [];
            $fileOutput = $run->stepResults['remove-file']?->output ?? [];
            $hardDeleted = (bool) ($hardOutput['hard_deleted'] ?? false);

            $deleted[] = [
                'project_key' => (string) ($loadOutput['project_key'] ?? $projectKey),
                'source_path' => (string) ($loadOutput['source_path'] ?? $sourcePath),
                'document_id' => (int) ($loadOutput['document_id'] ?? 0),
                'mode' => $hardDeleted ? 'hard' : 'soft',
                'file_deleted' => (bool) ($fileOutput['file_deleted'] ?? false),
                'status' => 'deleted',
            ];
        }

        return response()->json([
            'deleted' => count($deleted),
            'missing' => count($missing),
            'documents' => array_merge($deleted, $missing),
        ], 200);
    }
}

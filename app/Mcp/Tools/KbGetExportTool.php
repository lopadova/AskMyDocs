<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\KbWikiExportRequest;
use App\Support\TenantContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * v8.38/W4c (ADR 0032 §11) — read-only status/presentation for an export
 * request started via {@see KbCreateExportTool} or
 * `POST /api/admin/kb/exports`. Mirrors
 * {@see \App\Http\Controllers\Api\Admin\KbWikiExportController::show()}'s
 * presentation exactly (same fields, `download_url` present only once the
 * export is completed and not yet expired).
 *
 * Tenant-scoped (R30) — a foreign-tenant or unknown id both answer "not
 * found", never a cross-tenant existence leak.
 *
 * `download_url` is included for parity with the HTTP surface, but is
 * USEFUL only to a caller who separately holds a Sanctum session in a
 * browser — the download route re-authorizes via `auth:sanctum`
 * (`KbWikiExportController::download()`'s own docblock explains why: every
 * download must re-check the CURRENT session's principal, which an MCP
 * bearer token cannot stand in for). A pure MCP client cannot fetch bytes
 * through this URL on its own; building an MCP-native download path is out
 * of scope for this revision.
 */
#[Description('Report the status of a wiki export request started by KbCreateExportTool / POST /api/admin/kb/exports: queued/processing/completed/failed, document count, partial flag, and (once completed) a download_url. Read-only; tenant-scoped. Answers {disabled: true} when Wiki Export is off.')]
#[IsReadOnly]
#[IsIdempotent]
class KbGetExportTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()
                ->description('The export request id (UUID) returned by KbCreateExportTool.')
                ->required(),
        ];
    }

    public function handle(Request $request, TenantContext $tenants): Response
    {
        if (! (bool) config('kb.wiki_export.enabled', false)) {
            return Response::json(['disabled' => true, 'flag' => 'KB_WIKI_EXPORT_ENABLED']);
        }

        $id = trim((string) $request->get('id', ''));
        if ($id === '') {
            return Response::error('id is required.');
        }

        $exportRequest = KbWikiExportRequest::query()->forTenant($tenants->current())->find($id);
        if (! $exportRequest instanceof KbWikiExportRequest) {
            return Response::error("Export request {$id} not found.");
        }

        return Response::json([
            'id' => $exportRequest->id,
            'status' => $exportRequest->status,
            'project_key' => $exportRequest->project_key,
            'document_count' => $exportRequest->document_count,
            'partial' => $exportRequest->partial,
            'error_message' => $exportRequest->error_message,
            'expires_at' => $exportRequest->expires_at?->toIso8601String(),
            'completed_at' => $exportRequest->completed_at?->toIso8601String(),
            'download_url' => $exportRequest->isDownloadable()
                ? route('api.admin.kb.exports.download', ['id' => $exportRequest->id])
                : null,
        ]);
    }
}

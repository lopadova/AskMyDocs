<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\KnowledgeDocument;
use App\Services\Kb\Versioning\DocumentVersionService;
use App\Support\TenantContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * v8.36 / ADR 0030 §9 — the MCP read surface the v8.7 Time Machine never
 * got (R44). Lists a document's version family with the version provenance
 * (actor, reason, content hash, whether an artifact is stored) through the
 * SAME DocumentVersionService the CLI and HTTP surfaces use, tenant-scoped
 * (R30). It reads; it never restores, and it never returns content: the
 * artifact is the converter's output before the PII seam, so content stays
 * on the role-gated HTTP surface (documented R44 exception).
 */
#[Description('List a knowledge document\'s version family (newest first): id, status, whether it is live, who created the version and why, the artifact content hash and whether a stored conversion artifact exists. Read-only; tenant-scoped. Returns metadata only, never the content.')]
#[IsReadOnly]
#[IsIdempotent]
class KbDocumentVersionsTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'document_id' => $schema->integer()
                ->description('The knowledge_documents id of any version in the family.')
                ->required(),
        ];
    }

    public function handle(Request $request, DocumentVersionService $versions, TenantContext $tenants): Response
    {
        $id = (int) ($request->get('document_id') ?? 0);
        $document = KnowledgeDocument::query()->forTenant($tenants->current())->find($id);
        if ($document === null) {
            return Response::error("Document {$id} not found.");
        }

        $rows = $versions->versionsFor($document)->map(static fn (KnowledgeDocument $v): array => [
            'id' => (int) $v->id,
            'title' => $v->title,
            'version_hash' => $v->version_hash,
            'status' => $v->status,
            'is_live' => $v->status === 'active',
            'version_actor' => $v->version_actor,
            'version_reason' => $v->version_reason,
            'content_hash' => $v->content_hash,
            'has_artifact' => is_string($v->markdown_path) && $v->markdown_path !== '',
            'restored_by' => DocumentVersionService::lastRestoreOf($v)['actor'] ?? null,
            'restored_at' => DocumentVersionService::lastRestoreOf($v)['at'] ?? null,
            'indexed_at' => $v->indexed_at?->toIso8601String(),
        ])->all();

        return Response::json([
            'project_key' => $document->project_key,
            'source_path' => $document->source_path,
            'total' => count($rows),
            'versions' => $rows,
        ]);
    }
}

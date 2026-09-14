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
#[Description('List a knowledge document\'s version family (newest first): id, status, whether it is live, who created the version and why, the artifact content hash, whether a stored conversion artifact is readable and verified (has_artifact) and its verified state (artifact_state: none, verified, unverified, missing, mismatch). Read-only; tenant-scoped. Returns metadata only, never the content.')]
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
            'limit' => $schema->integer()
                ->description('Versions to list (1..the configured maximum, default the maximum); `truncated` says when the family holds more than the page.'),
            'offset' => $schema->integer()
                ->description('Newest versions to skip (the page cursor, default 0).'),
        ];
    }

    public function handle(Request $request, DocumentVersionService $versions, TenantContext $tenants): Response
    {
        $id = self::integerArgument($request->get('document_id'));
        if ($id === null || $id === false || $id < 1) {
            return Response::error('document_id must be a positive integer.');
        }
        $document = KnowledgeDocument::query()->forTenant($tenants->current())->find($id);
        if ($document === null) {
            return Response::error("Document {$id} not found.");
        }

        // The schema says integer and so does the check: `1.5`, `1e2` or
        // `" 7"` are refused, never truncated into a page nobody asked for.
        $requestedLimit = self::integerArgument($request->get('limit'));
        if ($requestedLimit === false || ($requestedLimit !== null && $requestedLimit < 1)) {
            return Response::error('limit must be a positive integer.');
        }
        $requestedOffset = self::integerArgument($request->get('offset'));
        if ($requestedOffset === false || ($requestedOffset !== null && $requestedOffset < 0)) {
            return Response::error('offset must be a non-negative integer.');
        }
        $limit = DocumentVersionService::timelineLimit($requestedLimit);
        $offset = $requestedOffset ?? 0;
        $total = $versions->familySizeFor($document);
        $rows = $versions->versionsFor($document, $limit, $offset)->map(function (KnowledgeDocument $v) use ($versions): array {
            // ADR 0030 §5 — read + verified, never the pointer alone.
            $artifactState = $versions->artifactStateFor($v);

            return [
            'id' => (int) $v->id,
            'title' => $v->title,
            'version_hash' => $v->version_hash,
            'status' => $v->status,
            'is_live' => $v->status === 'active',
            'version_actor' => $v->version_actor,
            'version_reason' => $v->version_reason,
            'content_hash' => $v->content_hash,
            'has_artifact' => DocumentVersionService::isVerifiedArtifactState($artifactState),
            'artifact_state' => $artifactState,
            'restored_by' => DocumentVersionService::lastRestoreOf($v)['actor'] ?? null,
            'restored_at' => DocumentVersionService::lastRestoreOf($v)['at'] ?? null,
            'indexed_at' => $v->indexed_at?->toIso8601String(),
            ];
        })->all();

        return Response::json([
            'project_key' => $document->project_key,
            'source_path' => $document->source_path,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'truncated' => $total > $offset + count($rows),
            'versions' => $rows,
        ]);
    }

    /**
     * An argument as an ACTUAL integer: PHP int, or a string of digits with
     * an optional sign — null when absent, false when it is anything else
     * (a float, scientific notation, padding, an array).
     */
    private static function integerArgument(mixed $value): int|null|false
    {
        if ($value === null) {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[+-]?\d+$/', $value) === 1) {
            return (int) $value;
        }

        return false;
    }
}

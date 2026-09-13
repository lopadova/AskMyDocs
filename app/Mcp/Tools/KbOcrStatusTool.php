<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\KnowledgeDocument;
use App\Services\Kb\Ocr\OcrService;
use App\Support\TenantContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * v8.36 / ADR 0029 — the MCP surface of OCR (R44), read-only by design.
 *
 * Reports what OCR recorded on a document (driver, reason, per-page
 * confidence, figures) through the same OcrService the CLI and HTTP
 * surfaces use, tenant-scoped (R30). There is deliberately NO MCP write
 * surface for OCR: re-running a conversion is a human decision (the cycle's
 * "agent proposes, a person commits" invariant).
 */
#[Description('Report what OCR recorded on a knowledge document: whether it was produced by OCR, the driver and reason, page count, mean/min confidence, per-page confidence and figure counts. Read-only; tenant-scoped.')]
#[IsReadOnly]
#[IsIdempotent]
class KbOcrStatusTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'document_id' => $schema->integer()
                ->description('The knowledge_documents id.')
                ->required(),
        ];
    }

    public function handle(Request $request, OcrService $ocr, TenantContext $tenants): Response
    {
        $id = (int) ($request->get('document_id') ?? 0);
        $document = KnowledgeDocument::query()->forTenant($tenants->current())->find($id);

        if ($document === null) {
            return Response::error("Document {$id} not found.");
        }

        return Response::json($ocr->status($document));
    }
}

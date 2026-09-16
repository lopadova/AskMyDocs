<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ValidatesIntegerArgument;
use App\Models\KnowledgeDocument;
use App\Services\Kb\Review\KbReviewService;
use App\Support\TenantContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * v8.37/W3 (ADR 0031 §8) — the MCP surface of Digitization Review, read-only
 * by design. Reports a document's page-review progress (total/reviewed/
 * unreviewed counts) through the same {@see KbReviewService} the HTTP and
 * CLI surfaces use, tenant-scoped (R30).
 *
 * There is deliberately NO MCP write surface for review status or document
 * approval (ADR 0031 §8, ADR 0003's boundary restated for OCR'd content: an
 * agent may point at a probable transcription error via
 * KbProposeTextCorrectionTool, a later W3 sub-branch, but it may never be
 * the one that marks anything "reviewed", "correct", or "approved" — that
 * is a human vouching for content the platform did not author).
 *
 * Registration stays flag-INDEPENDENT (ADR 0031 §1 — the roster is derived
 * from the files in app/Mcp/Tools/, KnowledgeBaseServerRegistrationTest's
 * own docblock explains why a config-dependent roster would desync from
 * that file list the moment the flag flips). A call while
 * KB_DIGITIZATION_REVIEW_ENABLED is off answers `{disabled: true, flag:
 * 'KB_DIGITIZATION_REVIEW_ENABLED'}`, never a 500 or a silent success.
 */
#[Description('Report a knowledge document\'s Digitization Review progress: total/reviewed/unreviewed page counts. Read-only; tenant-scoped. Answers {disabled: true} when the Digitization Review feature is off.')]
#[IsReadOnly]
#[IsIdempotent]
class KbReviewStatusTool extends Tool
{
    use ValidatesIntegerArgument;

    public function schema(JsonSchema $schema): array
    {
        return [
            'document_id' => $schema->integer()
                ->description('The knowledge_documents id.')
                ->required(),
        ];
    }

    public function handle(Request $request, KbReviewService $reviews, TenantContext $tenants): Response
    {
        if (! (bool) config('kb.review.enabled', false)) {
            return Response::json(['disabled' => true, 'flag' => 'KB_DIGITIZATION_REVIEW_ENABLED']);
        }

        $id = self::integerArgument($request->get('document_id'));
        if ($id === null || $id === false || $id < 1) {
            return Response::error('document_id must be a positive integer.');
        }

        $document = KnowledgeDocument::query()->forTenant($tenants->current())->find($id);
        if ($document === null) {
            return Response::error("Document {$id} not found.");
        }

        return Response::json($reviews->documentReviewSummary($document));
    }
}

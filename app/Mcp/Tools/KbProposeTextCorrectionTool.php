<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Exceptions\KbReviewRateLimitedException;
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

/**
 * v8.37/W3 (ADR 0031 §6) — the ONE MCP tool this cycle adds that writes
 * TOWARD the corpus, and even it writes only a CANDIDATE
 * (`kb_text_correction_candidates`), never `knowledge_documents` or its
 * chunks — the ADR 0003 `/suggest → /candidates → /promote` pattern,
 * restated for a probable OCR transcription error. Over the SAME
 * {@see KbReviewService::proposeCorrection()} core the future review UI's
 * "propose" action will use (R44).
 *
 * A write, so NOT annotated `#[IsReadOnly]` — the host tool-calling gate
 * requires super-admin, the same posture as every other write MCP tool
 * (`KbReembedProjectTool`, `KbDetokenizeTool`). `#[IsIdempotent]` IS
 * accurate here: a replayed call with the identical 7-tuple (tenant, actor,
 * document, version, page, old, new) returns the SAME candidate rather than
 * creating a second one ({@see KbReviewService::proposeCorrection()}'s
 * DB-enforced `idempotency_key`).
 *
 * There is deliberately no MCP tool to approve/reject a candidate or to
 * change review status (ADR 0031 §8, an R44 exception): a candidate has
 * zero effect on the corpus until a HUMAN reviewer accepts it through the
 * HTTP surface — an agent may point at a probable error, it may never be
 * the one that decides it is now correct.
 *
 * Registration stays flag-INDEPENDENT (ADR 0031 §1, same reasoning as
 * {@see KbReviewStatusTool}). A call while `KB_DIGITIZATION_REVIEW_ENABLED`
 * is off answers `{disabled: true, flag: ...}`, never a 500.
 */
#[Description('Propose a correction to a probable OCR transcription error on one page of a knowledge document. Writes a CANDIDATE only (kb_text_correction_candidates) — never applied to the corpus until a human reviewer approves it via the admin UI. old_text must occur exactly once on the page (ambiguous or absent is refused). Rate-limited per user; tenant-scoped; idempotent (a repeated identical proposal returns the same candidate). Requires super-admin. Answers {disabled: true} when Digitization Review is off.')]
#[IsIdempotent]
class KbProposeTextCorrectionTool extends Tool
{
    use ValidatesIntegerArgument;

    public function schema(JsonSchema $schema): array
    {
        return [
            'document_id' => $schema->integer()
                ->description('The knowledge_documents id the page belongs to.')
                ->required(),
            'page' => $schema->integer()
                ->description('The 1-based page number the correction applies to.')
                ->required(),
            'old_text' => $schema->string()
                ->description('The exact text to replace. Must occur exactly once on the named page.')
                ->required(),
            'new_text' => $schema->string()
                ->description('The proposed replacement text. At most 4000 characters.')
                ->required(),
            'rationale' => $schema->string()
                ->description('Optional. Why this is believed to be a transcription error. At most 500 characters.'),
        ];
    }

    public function handle(Request $request, KbReviewService $reviews, TenantContext $tenants): Response
    {
        if (! (bool) config('kb.review.enabled', false)) {
            return Response::json(['disabled' => true, 'flag' => 'KB_DIGITIZATION_REVIEW_ENABLED']);
        }

        $documentId = self::integerArgument($request->get('document_id'));
        if ($documentId === null || $documentId === false || $documentId < 1) {
            return Response::error('document_id must be a positive integer.');
        }

        $page = self::integerArgument($request->get('page'));
        if ($page === null || $page === false || $page < 1) {
            return Response::error('page must be a positive integer.');
        }

        $oldText = (string) $request->get('old_text', '');
        $newText = (string) $request->get('new_text', '');
        $rationaleRaw = $request->get('rationale');
        $rationale = is_string($rationaleRaw) && trim($rationaleRaw) !== '' ? $rationaleRaw : null;

        // Tenant boundary FIRST: a foreign-tenant document id is a 404, never
        // a cross-tenant existence leak (SEC-LLM-001 gate 5 / ADR 0031 §6).
        $document = KnowledgeDocument::query()->forTenant($tenants->current())->find($documentId);
        if ($document === null) {
            return Response::error("Document {$documentId} not found.");
        }

        // v8.37/W3b round 2 (Copilot PR #496) — the deployed MCP connection
        // authenticates a TENANT-scoped token (EnforceMcpScope), never a
        // per-user Sanctum session/token, so `auth()->user()` is genuinely
        // null on every real MCP call — `'user:'.(auth()->user()?->id ??
        // 'unknown')` was not a per-user identity at all, only ever
        // resolving to the single shared bucket `user:unknown` for every
        // caller in the tenant. A fixed, explicitly-scoped SERVICE identity
        // (mirroring KbWikiPromoteTool's `'mcp:kb-wiki-promote'`) makes that
        // sharing honest instead of implying a per-user distinction MCP
        // calls do not actually carry today — the rate-limit bucket,
        // idempotency tuple, `proposed_by` and audit actor are shared per
        // TENANT, not per fictitious "user".
        $actor = 'mcp:kb-propose-text-correction';

        try {
            $candidate = $reviews->proposeCorrection($document, $page, $oldText, $newText, $rationale, $actor);
        } catch (\InvalidArgumentException $e) {
            return Response::error($e->getMessage());
        } catch (KbReviewRateLimitedException $e) {
            return Response::error($e->getMessage());
        }

        return Response::json([
            'candidate_id' => $candidate->id,
            'document_id' => $candidate->knowledge_document_id,
            'page_number' => $candidate->page_number,
            'status' => $candidate->status,
            'idempotency_key' => $candidate->idempotency_key,
        ]);
    }
}

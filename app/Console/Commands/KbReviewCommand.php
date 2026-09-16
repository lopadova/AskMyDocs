<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\KbReviewDisabledException;
use App\Models\KnowledgeDocument;
use App\Services\Kb\Review\KbReviewService;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * v8.37/W3 (ADR 0031 §2/§4) — PHP/CLI surface (R44) of Digitization Review:
 * mark a page reviewed, approve the document (`auto -> human`), or report
 * its review summary. All three delegate to {@see KbReviewService}, the
 * shared core every surface adapts. Tri-surface for read/status + approve:
 * {@see \App\Http\Controllers\Api\Admin\KbReviewController} (HTTP) and
 * {@see \App\Mcp\Tools\KbReviewStatusTool} (MCP, read-only by ADR 0031 §8).
 * The text-correction-CANDIDATE flow (ADR 0031 §6-7 —
 * KbProposeTextCorrectionTool + its full SEC-AI-ACT-001 control set:
 * idempotency, rate limiting, R21 atomic single-use consumption) is a
 * separately-scoped capability that does not exist in KbReviewService yet
 * — there is no surface to add for a method that is not written — and
 * lands in its own W3 sub-branch.
 */
final class KbReviewCommand extends Command
{
    protected $signature = 'kb:review
        {document : knowledge_documents id}
        {--page= : mark this page number reviewed}
        {--approve : approve the document (auto -> human transition)}
        {--report : print the document review summary; no mutation}
        {--tenant=default : tenant to scope to}';

    protected $description = 'Mark a page reviewed, approve a document, or report review status (ADR 0031).';

    public function handle(KbReviewService $reviews, TenantContext $tenants): int
    {
        // Copilot PR #494 round 2 — TenantContext is a process-wide
        // singleton (KbOcrCommand established this restore pattern). Save
        // the caller's tenant and restore it in finally, on every return
        // path, so a second command invocation in the same Artisan/test
        // process never inherits this command's requested tenant.
        $previous = $tenants->current();
        $tenants->set((string) $this->option('tenant'));

        try {
            $document = KnowledgeDocument::query()
                ->forTenant($tenants->current())
                ->find((int) $this->argument('document'));
            if ($document === null) {
                $this->error('Document not found in tenant '.$this->option('tenant').'.');

                return self::FAILURE;
            }

            $actor = 'cli:kb:review';
            $didAnything = false;

            try {
                $page = $this->option('page');
                if ($page !== null) {
                    $didAnything = true;
                    $reviewed = $reviews->markPageReviewed($document, (int) $page, $actor);
                    $this->info("Page {$reviewed->page_number} marked reviewed.");
                }

                if ((bool) $this->option('approve')) {
                    $didAnything = true;
                    $result = $reviews->approve($document, $actor);
                    if (($result['approved'] ?? false) !== true) {
                        $this->warn('Not approved: '.($result['reason'] ?? 'unknown').'.');
                    } else {
                        $this->info('Document approved (auto -> human).');
                    }
                }
            } catch (KbReviewDisabledException $e) {
                // ADR 0031 §1 — a friendly disabled message, never an
                // uncaught exception bubbling out of the console command.
                $this->error($e->getMessage());

                return self::FAILURE;
            } catch (\InvalidArgumentException $e) {
                // KbReviewService::markPageReviewed() page_number guard
                // (Copilot PR #494) — same friendly-message posture.
                $this->error($e->getMessage());

                return self::FAILURE;
            }

            if ((bool) $this->option('report') || ! $didAnything) {
                $summary = $reviews->documentReviewSummary($document);
                $this->table(
                    ['total', 'reviewed', 'unreviewed'],
                    [[$summary['total'], $summary['reviewed'], $summary['unreviewed']]],
                );
            }

            return self::SUCCESS;
        } finally {
            $tenants->set($previous);
        }
    }
}

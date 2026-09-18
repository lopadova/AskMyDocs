<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\KbReviewDisabledException;
use App\Models\KnowledgeDocument;
use App\Services\Kb\Review\KbReviewService;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * v8.37/W3 (ADR 0031 §2/§4/§9) — PHP/CLI surface (R44) of Digitization
 * Review: set a page's review status, approve the document
 * (`auto -> human`), or report its review summary. All three delegate to
 * {@see KbReviewService}, the shared core every surface adapts. Tri-surface
 * for read/status + approve: {@see \App\Http\Controllers\Api\Admin\KbReviewController}
 * (HTTP) and {@see \App\Mcp\Tools\KbReviewStatusTool} (MCP, read-only by
 * ADR 0031 §8). The text-correction-CANDIDATE flow (ADR 0031 §6-7 —
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
        {--page= : set this page number\'s review status (use with --status)}
        {--status=reviewed : status to set the page to when --page is given (reviewed|unreviewed)}
        {--approve : approve the document (auto -> human transition)}
        {--report : print the document review summary; no mutation}
        {--tenant=default : tenant to scope to}';

    protected $description = 'Set a page\'s review status, approve a document, or report review status (ADR 0031).';

    public function handle(KbReviewService $reviews, TenantContext $tenants): int
    {
        // Copilot PR #494 round 4 — `(int) $raw` silently truncates
        // malformed input: `kb:review 12.5 --approve` would become document
        // 12 and could approve the WRONG document. A real CLI invocation
        // always hands arguments/options as strings, so validating the
        // string shape here (not the already-narrowed int) is what
        // actually catches "12.5" / "abc" / "-1" before anything acts on
        // it.
        $documentIdRaw = (string) $this->argument('document');
        $documentId = $this->parsePositiveInteger($documentIdRaw);
        if ($documentId === null) {
            $this->error("document must be a positive integer, got '{$documentIdRaw}'.");

            return self::FAILURE;
        }

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
                ->find($documentId);
            if ($document === null) {
                $this->error('Document not found in tenant '.$this->option('tenant').'.');

                return self::FAILURE;
            }

            $actor = 'cli:kb:review';
            $didAnything = false;

            try {
                $pageRaw = $this->option('page');
                if ($pageRaw !== null) {
                    $page = $this->parsePositiveInteger((string) $pageRaw);
                    if ($page === null) {
                        $this->error("--page must be a positive integer, got '{$pageRaw}'.");

                        return self::FAILURE;
                    }

                    $didAnything = true;
                    $status = (string) $this->option('status');
                    $reviewed = $reviews->setPageReviewStatus($document, $page, $status, $actor);
                    $this->info("Page {$reviewed->page_number} set to '{$reviewed->status}'.");
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
                // KbReviewService's page_number / page_count / status
                // guards (Copilot PR #494) — same friendly-message posture.
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

    /**
     * Accepts only a bare non-negative-looking integer string ("1", "12"),
     * never "1.5" / "-1" / "1e3" / "" / "abc" / a leading-plus-signed value
     * — `filter_var(..., FILTER_VALIDATE_INT)` alone would still accept
     * " 1" or "+1"; the regex keeps this to exactly what a positive
     * Eloquent primary key or page number can look like.
     */
    private function parsePositiveInteger(string $raw): ?int
    {
        if (preg_match('/^\d+$/', $raw) !== 1) {
            return null;
        }

        $value = (int) $raw;

        return $value >= 1 ? $value : null;
    }
}

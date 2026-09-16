<?php

declare(strict_types=1);

namespace App\Flow\Steps\Prune;

use App\Flow\Steps\StepTenantBinder;
use App\Models\KnowledgeDocument;
use App\Services\Kb\DocumentDeleter;
use DateTimeImmutable;
use Padosoft\LaravelFlow\FlowContext;
use Padosoft\LaravelFlow\FlowStepHandler;
use Padosoft\LaravelFlow\FlowStepResult;
use RuntimeException;

/**
 * Step 2 of {@see \App\Flow\Definitions\PruneDeletedFlow}.
 *
 * Hard-deletes the soft-deleted documents counted by step 1. Walks the
 * tenant-scoped onlyTrashed() set with chunkById(100) (R3) and routes
 * each row through {@see DocumentDeleter::delete()} with `force=true`
 * so chunks cascade, kb_node/kb_edge cascade, and the deprecation
 * audit row is written.
 *
 * Dry-run skipped — DB + disk mutation is the only artefact.
 */
final class HardDeleteSoftDeletedStep implements FlowStepHandler
{
    public function __construct(
        private readonly DocumentDeleter $deleter,
    ) {}

    public function execute(FlowContext $context): FlowStepResult
    {
        StepTenantBinder::bindFromContext($context);

        if ($context->dryRun) {
            return FlowStepResult::dryRunSkipped();
        }

        $tenantId = (string) $context->input['tenant_id'];
        $cutoff = $this->parseCutoff($context->input['cutoff_iso'] ?? null);

        $deleted = 0;
        // v8.36 / ADR 0030 §3 — a hard delete can legitimately KEEP the
        // source file (another writer holds its storage key, the cache store
        // cannot exclude anyone, the lock lapsed mid-section). The rows are
        // gone either way, so a run that left bytes behind must SAY so
        // (R14): otherwise "Pruned N document(s)" reads as a completed
        // cleanup and the orphan sweep's later work looks unexplained.
        $filesKept = 0;
        // R3 — chunkById uses `id > ?` cursoring so it stays correct even
        // though forceDelete() removes each row as we iterate.
        // R30 — explicit tenant scope on the read.
        KnowledgeDocument::query()
            ->forTenant($tenantId)
            ->onlyTrashed()
            ->where('deleted_at', '<', $cutoff)
            ->orderBy('id')
            ->chunkById(100, function ($rows) use (&$deleted, &$filesKept): void {
                foreach ($rows as $row) {
                    // PR #492 Copilot round-1 — a `markdown_only`-retention
                    // row keeps a non-empty `source_path` after its original
                    // was intentionally dropped (`DocumentIngestor`, same
                    // reading as `DocumentDeleter::deleteOrphans()` above):
                    // `delete()` then answers `file_deleted=false` for a file
                    // that was never there to keep, and counting it here
                    // reports bytes retained that `kb:prune-orphan-files` has
                    // nothing to reap.
                    $metadata = is_array($row->metadata) ? $row->metadata : [];
                    $sourceWasDropped = ($metadata['source_dropped'] ?? false) === true;
                    $hadFile = $row->source_path !== null && $row->source_path !== '' && ! $sourceWasDropped;
                    $result = $this->deleter->delete($row, force: true);
                    if ($hadFile && ($result['file_deleted'] ?? false) === false) {
                        $filesKept++;
                    }
                    $deleted++;
                }
            });

        return FlowStepResult::success(
            output: [
                'tenant_id' => $tenantId,
                'cutoff_iso' => $cutoff->format(\DateTimeInterface::ATOM),
                'deleted_count' => $deleted,
                // Additive (R27). Counts a row whose source was NOT removed —
                // including the ordinary case of a file another version still
                // references, which is why it is reported, not failed.
                'files_kept' => $filesKept,
            ],
            businessImpact: ['deleted_count' => $deleted, 'files_kept' => $filesKept],
        );
    }

    private function parseCutoff(mixed $raw): DateTimeImmutable
    {
        if (! is_string($raw) || $raw === '') {
            throw new RuntimeException(
                'HardDeleteSoftDeletedStep: input["cutoff_iso"] must be a non-empty ISO 8601 string.'
            );
        }
        try {
            return new DateTimeImmutable($raw);
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'HardDeleteSoftDeletedStep: input["cutoff_iso"] is not a valid ISO 8601 timestamp.',
                previous: $e,
            );
        }
    }
}

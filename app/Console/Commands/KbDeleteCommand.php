<?php

namespace App\Console\Commands;

use App\Flow\Definitions\DeleteDocumentFlow;
use App\Support\KbPath;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Padosoft\LaravelFlow\Facades\Flow;
use Padosoft\LaravelFlow\FlowExecutionOptions;
use Padosoft\LaravelFlow\FlowRun;

/**
 * Deletes a single knowledge document (DB + chunks + optionally the
 * original file on the KB disk).
 *
 * v4.2/W2 PR #116: now dispatches the {@see DeleteDocumentFlow} saga.
 * Behaviour is preserved: same flags, same exit codes, same operator
 * UX. The flow adds observable per-step Flow rows + a soft-delete
 * compensator that restores the row if the hard-delete-rows step fails.
 */
class KbDeleteCommand extends Command
{
    protected $signature = 'kb:delete
                            {path : Source path of the document, relative to KB_PATH_PREFIX}
                            {--project= : Project key (defaults to KB_INGEST_DEFAULT_PROJECT)}
                            {--force : Hard delete (DB + file) even if KB_SOFT_DELETE_ENABLED=true}
                            {--soft : Soft delete (deleted_at only) even if KB_SOFT_DELETE_ENABLED=false}
                            {--keep-file : Hard delete DB rows but keep the source file on disk (only meaningful with --force)}';

    protected $description = 'Delete a knowledge document from the RAG store (and optionally the source file), via DeleteDocumentFlow.';

    public function handle(TenantContext $tenants): int
    {
        if ($this->option('force') && $this->option('soft')) {
            $this->error('Cannot combine --force and --soft.');
            return self::FAILURE;
        }

        try {
            $sourcePath = KbPath::normalize((string) $this->argument('path'));
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $projectKey = (string) ($this->option('project') ?: config('kb.ingest.default_project', 'default'));

        // Resolve `force` from CLI flags + the global default. Same semantics
        // as the legacy KbDeleteCommand: --force overrides config, --soft
        // overrides config, otherwise the config default wins.
        $force = ! (bool) config('kb.deletion.soft_delete', true);
        if ($this->option('force')) {
            $force = true;
        } elseif ($this->option('soft')) {
            $force = false;
        }

        $tenantId = $tenants->current();

        $run = Flow::execute(
            DeleteDocumentFlow::NAME,
            [
                'tenant_id' => $tenantId,
                'project_key' => $projectKey,
                'source_path' => $sourcePath,
                'force' => $force,
                'keep_file' => (bool) $this->option('keep-file'),
            ],
            FlowExecutionOptions::make(
                correlationId: $tenantId,
            ),
        );

        if ($run->status !== FlowRun::STATUS_SUCCEEDED) {
            $failedStep = $run->failedStep ?? '(unknown)';
            $this->error("Delete flow failed at step [{$failedStep}] with status [{$run->status}].");
            return self::FAILURE;
        }

        $loadOutput = $run->stepResults['load-document']?->output ?? [];
        if (! ($loadOutput['found'] ?? false)) {
            $this->error("No document found for project [{$projectKey}] at [{$sourcePath}].");
            return self::FAILURE;
        }

        $documentId = (int) $loadOutput['document_id'];
        $hardOutput = $run->stepResults['hard-delete-rows']?->output ?? [];
        $fileOutput = $run->stepResults['remove-file']?->output ?? [];
        $hardDeleted = (bool) ($hardOutput['hard_deleted'] ?? false);
        $fileDeleted = (bool) ($fileOutput['file_deleted'] ?? false);

        $mode = $hardDeleted ? 'hard-deleted' : 'soft-deleted';
        $fileNote = '';
        if ($hardDeleted) {
            $fileNote = $fileDeleted
                ? ' (file removed)'
                : ($this->option('keep-file') ? ' (file preserved by --keep-file)' : ' (no file on disk)');
        }
        $this->info("Document #{$documentId} {$mode} [{$projectKey}/{$sourcePath}]{$fileNote}.");

        return self::SUCCESS;
    }
}

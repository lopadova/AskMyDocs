<?php

namespace App\Console\Commands;

use App\Services\Kb\DocumentDeleter;
use App\Support\KbPath;
use Illuminate\Console\Command;

/**
 * Deletes a single knowledge document (DB + chunks + optionally the
 * original file on the KB disk).
 *
 * By default follows the `kb.deletion.soft_delete` config flag. Override
 * with `--force` (hard) or `--soft` (soft, no matter the config). Returns
 * failure if no matching document is found so CI pipelines can flag typos.
 */
class KbDeleteCommand extends Command
{
    protected $signature = 'kb:delete
                            {path : Source path of the document, relative to KB_PATH_PREFIX}
                            {--project= : Project key (defaults to KB_INGEST_DEFAULT_PROJECT)}
                            {--force : Hard delete (DB + file) even if KB_SOFT_DELETE_ENABLED=true}
                            {--soft : Soft delete (deleted_at only) even if KB_SOFT_DELETE_ENABLED=false}';

    protected $description = 'Delete a knowledge document from the RAG store (and optionally the source file).';

    public function handle(DocumentDeleter $deleter): int
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

        $force = null;
        if ($this->option('force')) {
            $force = true;
        } elseif ($this->option('soft')) {
            $force = false;
        }

        $result = $deleter->deleteByPath($projectKey, $sourcePath, $force);

        if ($result === null) {
            $this->error("No document found for project [{$projectKey}] at [{$sourcePath}].");

            return self::FAILURE;
        }

        $mode = $result['mode'] === 'hard' ? 'hard-deleted' : 'soft-deleted';
        $fileNote = $result['mode'] === 'hard'
            ? ($result['file_deleted'] ? ' (file removed)' : ' (no file on disk)')
            : '';
        $this->info("Document #{$result['document_id']} {$mode} [{$projectKey}/{$sourcePath}]{$fileNote}.");

        return self::SUCCESS;
    }
}

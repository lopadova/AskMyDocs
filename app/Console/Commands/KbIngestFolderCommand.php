<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Flow\Definitions\IngestFolderFlow;
use App\Support\Kb\SourceType;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Padosoft\LaravelFlow\Facades\Flow;
use Padosoft\LaravelFlow\FlowExecutionOptions;
use Padosoft\LaravelFlow\FlowRun;
use Padosoft\LaravelFlow\FlowStepResult;

/**
 * Walks a folder on the configured Laravel disk and dispatches one
 * IngestDocumentJob per supported file found.
 *
 * v4.2/sub-PR 3d — refactored onto {@see IngestFolderFlow}. The Flow
 * orchestrates list-files → dispatch-fan-out → maybe-prune-orphans;
 * each per-file dispatch is itself a {@see \App\Flow\Definitions\IngestDocumentFlow}
 * sub-saga (CanonicalIndexerJob handles canonical fan-out from there).
 *
 * Back-compat: every existing CLI option keeps its original meaning
 * (--project, --disk, --pattern, --recursive, --sync, --limit,
 * --dry-run, --prune-orphans, --force-delete). Added: --tenant for
 * R30 fan-out across multiple tenants.
 */
class KbIngestFolderCommand extends Command
{
    protected $signature = 'kb:ingest-folder
                            {path? : Folder on the KB disk, relative to KB_PATH_PREFIX (defaults to the prefix root)}
                            {--project= : Project key for multi-tenant filtering (defaults to KB_INGEST_DEFAULT_PROJECT)}
                            {--tenant= : Restrict ingest to a single tenant_id (default: current TenantContext)}
                            {--disk= : Override KB_FILESYSTEM_DISK for this run}
                            {--pattern= : Comma-separated extension patterns (default: every supported format)}
                            {--recursive : Walk sub-directories}
                            {--sync : Run ingestion inline without touching the queue}
                            {--limit=0 : Stop after N files (0 = unlimited)}
                            {--dry-run : Print matches without dispatching}
                            {--prune-orphans : Delete documents under this folder whose source file no longer exists}
                            {--force-delete : When pruning orphans, hard-delete instead of using KB_SOFT_DELETE_ENABLED default}';

    protected $description = 'Walk a folder on the KB disk and dispatch a queued ingestion job per supported file (md/markdown/txt/pdf/docx).';

    public function handle(TenantContext $context): int
    {
        $disk = (string) ($this->option('disk') ?: config('kb.sources.disk', 'kb'));
        $projectKey = (string) ($this->option('project') ?: config('kb.ingest.default_project', 'default'));
        $prefix = trim((string) config('kb.sources.path_prefix', ''), '/');
        $pathArg = trim((string) ($this->argument('path') ?? ''), '/');

        // The path argument is relative to KB_PATH_PREFIX so the queued job
        // (which re-applies the prefix when reading) can find the file.
        $basePath = $prefix === ''
            ? $pathArg
            : ($pathArg === '' ? $prefix : $prefix.'/'.$pathArg);

        $recursive = (bool) $this->option('recursive');
        $dryRun = (bool) $this->option('dry-run');
        $sync = (bool) $this->option('sync');
        $limit = max(0, (int) $this->option('limit'));
        $extensions = $this->parsePatterns((string) ($this->option('pattern') ?? ''));
        $pruneOrphans = (bool) $this->option('prune-orphans');
        $forceDeleteRaw = $this->option('force-delete');
        $forceDelete = $forceDeleteRaw ? true : null;

        $tenantId = (string) ($this->option('tenant') ?? $context->current());
        $previousTenant = $context->current();

        try {
            $context->set($tenantId);

            $input = [
                'tenant_id' => $tenantId,
                'project_key' => $projectKey,
                'disk' => $disk,
                'base_path' => $basePath,
                'extensions' => $extensions,
                'recursive' => $recursive,
                'sync' => $sync,
                'limit' => $limit,
                'prefix' => $prefix,
                'prune_orphans' => $pruneOrphans,
                'force_delete' => $forceDelete,
                'relative_base_path' => $pathArg,
            ];

            // hrtime nonce so re-runs after manual file edits ALWAYS
            // re-execute (otherwise the engine's per-(name, key) dedup
            // would short-circuit and skip newly-added files).
            $nonce = (string) hrtime(true);
            $options = FlowExecutionOptions::make(
                correlationId: $tenantId,
                idempotencyKey: "ingest-folder:{$tenantId}:{$disk}:{$basePath}:{$nonce}",
            );

            $run = $dryRun
                ? Flow::dryRun(IngestFolderFlow::NAME, $input, $options)
                : Flow::execute(IngestFolderFlow::NAME, $input, $options);

            return $this->reportRun($run, $disk, $basePath, $projectKey, $dryRun, $sync, $pruneOrphans);
        } finally {
            $context->set($previousTenant);
        }
    }

    private function reportRun(
        FlowRun $run,
        string $disk,
        string $basePath,
        string $projectKey,
        bool $dryRun,
        bool $sync,
        bool $pruneOrphans,
    ): int {
        if ($run->status !== FlowRun::STATUS_SUCCEEDED) {
            $failedStep = $run->failedStep ?? '(unknown)';
            $this->error("kb.ingest-folder [{$run->status}] at step [{$failedStep}].");
            return self::FAILURE;
        }

        $listResult = $run->stepResults['list-files'] ?? null;
        $matched = $listResult instanceof FlowStepResult
            ? (int) ($listResult->output['matched_count'] ?? 0)
            : 0;
        $files = $listResult instanceof FlowStepResult
            ? (array) ($listResult->output['matched_files'] ?? [])
            : [];

        if ($matched === 0) {
            // Legacy phrasing ("No markdown files matched") preserved for
            // back-compat with operator scripts and existing test
            // assertions across the v4.2 refactor.
            $this->warn("No markdown files matched under disk [{$disk}] path [{$basePath}].");
            $this->reportOrphanResult($run, $pruneOrphans, $dryRun);
            return self::SUCCESS;
        }

        $mode = $dryRun ? 'DRY-RUN' : ($sync ? 'SYNC' : 'QUEUE');
        $this->info("[{$mode}] Found {$matched} file(s) on disk [{$disk}] — project [{$projectKey}].");

        if ($dryRun) {
            foreach ($files as $f) {
                if (! is_string($f)) {
                    continue;
                }
                $ext = (string) pathinfo($f, PATHINFO_EXTENSION);
                $sourceType = SourceType::fromExtension($ext);
                $this->line("  • {$f} [{$sourceType->value}]");
            }
            $this->info("Would dispatch {$matched} job(s). No changes made.");
            return self::SUCCESS;
        }

        $dispatchResult = $run->stepResults['dispatch-ingest-fan-out'] ?? null;
        $dispatched = $dispatchResult instanceof FlowStepResult
            ? (int) ($dispatchResult->output['dispatched_count'] ?? 0)
            : 0;
        $failures = $dispatchResult instanceof FlowStepResult
            ? (array) ($dispatchResult->output['failures'] ?? [])
            : [];
        $failureCount = count($failures);

        foreach ($failures as $failure) {
            if (! is_array($failure)) {
                continue;
            }
            $this->error("  ! failed: {$failure['path']} — {$failure['reason']}");
        }

        $verb = $sync ? 'Ingested' : 'Queued';
        $tail = $failureCount > 0 ? " — {$failureCount} failure(s)." : '.';
        $this->info("{$verb} {$dispatched} document(s){$tail}");

        $this->reportOrphanResult($run, $pruneOrphans, $dryRun);

        return $failureCount === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function reportOrphanResult(FlowRun $run, bool $pruneOrphans, bool $dryRun): void
    {
        if (! $pruneOrphans || $dryRun) {
            return;
        }
        $orphans = $run->stepResults['prune-orphans'] ?? null;
        if (! ($orphans instanceof FlowStepResult)) {
            return;
        }
        $count = (int) ($orphans->output['orphans_deleted_count'] ?? 0);
        if ($count === 0) {
            $this->info('No orphan documents to prune.');
            return;
        }
        $force = $orphans->output['force'] ?? null;
        $mode = $force === true ? 'hard' : (config('kb.deletion.soft_delete', true) ? 'soft' : 'hard');
        $this->info(sprintf('Pruned %d orphan document(s) [%s delete].', $count, $mode));
        $rows = (array) ($orphans->output['orphans_deleted'] ?? []);
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $sp = (string) ($row['source_path'] ?? '');
            $this->line("  - {$sp}");
        }
    }

    /**
     * @return list<string>
     */
    private function parsePatterns(string $raw): array
    {
        if ($raw === '') {
            // Default to every supported source-type extension.
            return SourceType::knownExtensions();
        }
        $parts = array_filter(array_map('trim', explode(',', $raw)));
        $extensions = [];
        foreach ($parts as $pattern) {
            $pattern = ltrim($pattern, '*');
            $pattern = ltrim($pattern, '.');
            if ($pattern !== '') {
                $extensions[] = strtolower($pattern);
            }
        }
        return array_values(array_unique($extensions));
    }
}

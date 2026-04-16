<?php

namespace App\Console\Commands;

use App\Jobs\IngestDocumentJob;
use App\Services\Kb\DocumentIngestor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Walks a folder on the configured Laravel disk and dispatches one
 * IngestDocumentJob per markdown file found.
 *
 * With QUEUE_CONNECTION=sync every dispatch executes inline; with
 * `database` or `redis` jobs are enqueued for a worker to process.
 * The `--sync` option bypasses the queue altogether (useful when you
 * want progress feedback on small batches in dev).
 */
class KbIngestFolderCommand extends Command
{
    protected $signature = 'kb:ingest-folder
                            {path? : Base folder on the KB disk (defaults to KB_PATH_PREFIX or "")}
                            {--project= : Project key for multi-tenant filtering (defaults to KB_INGEST_DEFAULT_PROJECT)}
                            {--disk= : Override KB_FILESYSTEM_DISK for this run}
                            {--pattern= : Comma-separated extension patterns (default: md,markdown)}
                            {--recursive : Walk sub-directories}
                            {--sync : Run ingestion inline without touching the queue}
                            {--limit=0 : Stop after N files (0 = unlimited)}
                            {--dry-run : Print matches without dispatching}';

    protected $description = 'Walk a folder on the KB disk and dispatch a queued ingestion job per markdown file.';

    public function handle(DocumentIngestor $ingestor): int
    {
        $disk = (string) ($this->option('disk') ?: config('kb.sources.disk', 'kb'));
        $projectKey = (string) ($this->option('project') ?: config('kb.ingest.default_project', 'default'));
        $prefix = (string) config('kb.sources.path_prefix', '');
        $basePath = (string) ($this->argument('path') ?? '');

        if ($basePath === '') {
            $basePath = $prefix;
        }

        $basePath = ltrim($basePath, '/');
        $recursive = (bool) $this->option('recursive');
        $dryRun = (bool) $this->option('dry-run');
        $sync = (bool) $this->option('sync');
        $limit = max(0, (int) $this->option('limit'));
        $patternRaw = (string) ($this->option('pattern') ?: 'md,markdown');
        $patterns = $this->parsePatterns($patternRaw);

        $storage = Storage::disk($disk);

        $allFiles = $recursive ? $storage->allFiles($basePath) : $storage->files($basePath);
        $matching = $this->filterByPatterns($allFiles, $patterns);

        if ($limit > 0) {
            $matching = array_slice($matching, 0, $limit);
        }

        $total = count($matching);

        if ($total === 0) {
            $this->warn("No markdown files matched under disk [{$disk}] path [{$basePath}].");

            return self::SUCCESS;
        }

        $mode = $dryRun ? 'DRY-RUN' : ($sync ? 'SYNC' : 'QUEUE');
        $this->info("[{$mode}] Found {$total} file(s) on disk [{$disk}] — project [{$projectKey}].");

        $dispatched = 0;
        $failed = 0;

        foreach ($matching as $fullPath) {
            $relative = $this->stripPrefix($fullPath, $prefix);

            if ($dryRun) {
                $this->line("  • {$fullPath}");
                continue;
            }

            try {
                if ($sync) {
                    if (! $storage->exists($fullPath)) {
                        throw new \RuntimeException("File vanished before ingestion: {$fullPath}");
                    }
                    $markdown = (string) $storage->get($fullPath);
                    $title = pathinfo($relative, PATHINFO_FILENAME);

                    $ingestor->ingestMarkdown(
                        projectKey: $projectKey,
                        sourcePath: $relative,
                        title: $title,
                        markdown: $markdown,
                        metadata: ['disk' => $disk, 'prefix' => $prefix],
                    );
                } else {
                    IngestDocumentJob::dispatch(
                        projectKey: $projectKey,
                        relativePath: $relative,
                        disk: $disk,
                    );
                }

                $dispatched++;
            } catch (\Throwable $e) {
                $failed++;
                $this->error("  ! failed: {$relative} — {$e->getMessage()}");
            }
        }

        if ($dryRun) {
            $this->info("Would dispatch {$total} job(s). No changes made.");

            return self::SUCCESS;
        }

        $verb = $sync ? 'Ingested' : 'Queued';
        $this->info("{$verb} {$dispatched} document(s)".($failed > 0 ? " — {$failed} failure(s)." : '.'));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<int,string>
     */
    private function parsePatterns(string $raw): array
    {
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

    /**
     * @param  array<int,string>  $files
     * @param  array<int,string>  $extensions
     * @return array<int,string>
     */
    private function filterByPatterns(array $files, array $extensions): array
    {
        if ($extensions === []) {
            return $files;
        }

        return array_values(array_filter($files, function (string $path) use ($extensions) {
            $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

            return in_array($ext, $extensions, true);
        }));
    }

    private function stripPrefix(string $path, string $prefix): string
    {
        $prefix = trim($prefix, '/');
        if ($prefix === '') {
            return ltrim($path, '/');
        }

        $path = ltrim($path, '/');
        if (str_starts_with($path, $prefix.'/')) {
            return substr($path, strlen($prefix) + 1);
        }

        return $path;
    }
}

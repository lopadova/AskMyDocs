<?php

namespace App\Console\Commands;

use App\Services\Kb\DocumentIngestor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class KbIngestCommand extends Command
{
    protected $signature = 'kb:ingest
                            {path : Path of the markdown file relative to the KB disk root}
                            {--project= : Project key for multi-tenant filtering}
                            {--title= : Document title (defaults to basename)}
                            {--disk= : Override KB_FILESYSTEM_DISK for this run}';

    protected $description = 'Ingest a markdown document into the knowledge base via the configured Laravel disk.';

    public function handle(DocumentIngestor $ingestor): int
    {
        $relativePath = (string) $this->argument('path');
        $projectKey = (string) ($this->option('project') ?? 'default');
        $disk = (string) ($this->option('disk') ?: config('kb.sources.disk', 'kb'));
        $prefix = (string) config('kb.sources.path_prefix', '');
        $fullPath = ltrim($prefix.'/'.ltrim($relativePath, '/'), '/');

        if (! Storage::disk($disk)->exists($fullPath)) {
            $this->error("Markdown file not found on disk [{$disk}]: {$fullPath}");
            return self::FAILURE;
        }

        $markdown = Storage::disk($disk)->get($fullPath);
        if (! is_string($markdown)) {
            // `exists()` said yes, `get()` said nothing (an adapter that
            // refuses the read without `throw`): one line, not a TypeError.
            $this->error("Disk [{$disk}] returned no bytes for {$fullPath}; nothing was ingested.");

            return self::FAILURE;
        }
        $title = (string) ($this->option('title') ?? pathinfo($relativePath, PATHINFO_FILENAME));

        try {
            $document = $ingestor->ingestMarkdown(
                projectKey: $projectKey,
                sourcePath: $relativePath,
                title: $title,
                markdown: $markdown,
                metadata: [
                    'disk' => $disk,
                    'prefix' => $prefix,
                ],
            );
        } catch (\App\Services\Kb\Versioning\ArtifactPublishFailedException $e) {
            // The document IS committed (the row is there, the version reads
            // through reconstruction); only its artifact is missing. Say so
            // in one line, with the repair, instead of a stack trace (R14).
            $this->error("Document #{$e->documentId} was ingested from {$disk}://{$fullPath}, but its conversion artifact could not be published on disk [{$e->disk}] ({$e->markdownPath}); re-run this command or `kb:artifacts-backfill` to repair it.");

            return self::FAILURE;
        }

        $this->info("Ingested document #{$document->id} ({$title}) from {$disk}://{$fullPath}.");

        return self::SUCCESS;
    }
}

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
        $title = (string) ($this->option('title') ?? pathinfo($relativePath, PATHINFO_FILENAME));

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

        $this->info("Ingested document #{$document->id} ({$title}) from {$disk}://{$fullPath}.");

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Services\Kb\DocumentIngestor;
use App\Support\KbPath;
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
        $rawPath = (string) $this->argument('path');
        $projectKey = (string) ($this->option('project') ?? 'default');
        $disk = (string) ($this->option('disk') ?: config('kb.sources.disk', 'kb'));
        $prefix = (string) config('kb.sources.path_prefix', '');

        // v8.36 / ADR 0029 §6 / PR #479 Copilot review round 4 — R1: every
        // KB source path goes through `KbPath::normalize()` before any disk
        // op or path-shape decision, the same contract the HTTP/folder entry
        // points already follow. A raw `docs/../outside.md` reaching
        // Storage::exists()/get() unnormalized is a traversal risk (and on a
        // driver that rejects `..` outright, an uncaught throw outside this
        // command's clean R14 error handling); the generated-asset check two
        // lines down is meaningless run against a path that could still
        // smuggle `..` past it. `$relativePath` (used below as both the
        // ingested `sourcePath` and the title fallback) is the normalized
        // bare path — `DocumentIngestor::ingest()` normalizes it again
        // internally, but idempotently, so nothing downstream diverges.
        try {
            $relativePath = KbPath::normalize($rawPath);
            $fullPath = $prefix === '' ? $relativePath : KbPath::normalize($prefix.'/'.$relativePath);
        } catch (\InvalidArgumentException $e) {
            $this->error("Invalid source path [{$rawPath}]: {$e->getMessage()}");

            return self::FAILURE;
        }

        // v8.36 / ADR 0029 §6 — the converters' own output (`{source}.ocr/`,
        // `.artifacts/`) is never a source: `KbIngestController` and
        // `ListFolderFilesStep` already reject it before a row is ever
        // created (accepting it would let a caller re-ingest a recorded run
        // or artifact and self-ingest it). This single-file CLI had no such
        // guard — checked here, before any disk read, so the rejection is a
        // clean one-line error (R14) instead of the RuntimeException
        // `ConversionArtifactStore::pathFor()` now throws as the last-resort
        // guard every artifact-path composition shares.
        if (KbPath::isGeneratedAsset($fullPath)) {
            $this->error("Path [{$fullPath}] is inside a generated-asset directory (.ocr/ or .artifacts/) and cannot be ingested as a source.");

            return self::FAILURE;
        }

        if (! Storage::disk($disk)->exists($fullPath)) {
            $this->error("Markdown file not found on disk [{$disk}]: {$fullPath}");
            return self::FAILURE;
        }

        try {
            $markdown = Storage::disk($disk)->get($fullPath);
        } catch (\Throwable $e) {
            // `exists()` returning true does not guarantee `get()` succeeds:
            // the file can vanish in the gap, or a driver configured to
            // `throw` on failure raises instead of returning null. Same
            // clean one-line failure as the no-bytes case below (R14) —
            // never a stack trace out of a CLI command.
            $this->error("Disk [{$disk}] could not be read for {$fullPath}: {$e->getMessage()}; nothing was ingested.");

            return self::FAILURE;
        }
        if (! is_string($markdown) || $markdown === '') {
            // `exists()` said yes, `get()` said nothing (an adapter that
            // refuses the read without `throw`, a zero-byte object): one
            // line, not a TypeError and never an empty version (R14).
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

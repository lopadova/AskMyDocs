<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\IngestDocumentJob;
use App\Services\Kb\Canonical\CanonicalParser;
use App\Services\Kb\Canonical\CanonicalWriter;
use Illuminate\Console\Command;

/**
 * Operator-side promotion of a local canonical markdown file.
 *
 * Reads the file, validates its frontmatter via {@see CanonicalParser},
 * writes it to the configured KB disk via {@see CanonicalWriter}, and
 * dispatches {@see IngestDocumentJob}. Not used by Claude skills — they
 * stop at the HTTP API's `candidates` endpoint. This CLI exists for
 * operators / scripts that batch-promote files without going through HTTP.
 */
class KbPromoteCommand extends Command
{
    protected $signature = 'kb:promote
        {path : Local filesystem path to the canonical markdown file}
        {--project= : Project key for the canonical doc (required)}
        {--dry-run : Validate + print the resolved target path, write nothing}';

    protected $description = 'Promote a local canonical markdown file to the KB (write + dispatch ingest).';

    public function handle(CanonicalParser $parser, CanonicalWriter $writer): int
    {
        $path = (string) $this->argument('path');
        $projectKey = (string) ($this->option('project') ?? '');
        $dryRun = (bool) $this->option('dry-run');

        if ($projectKey === '') {
            $this->error('--project is required.');
            return self::INVALID;
        }
        if (! is_file($path)) {
            $this->error("File not found: {$path}");
            return self::FAILURE;
        }

        // `file_get_contents()` returns `false` on a read error (missing
        // permissions, locked file, OS-level failure). Distinguish that
        // from a genuinely empty file so operators see the real cause.
        $markdown = @file_get_contents($path);
        if ($markdown === false) {
            $this->error("File is unreadable (permission denied or OS error): {$path}");
            return self::FAILURE;
        }
        if (trim($markdown) === '') {
            $this->error("File is empty: {$path}");
            return self::FAILURE;
        }

        $parsed = $parser->parse($markdown);
        if ($parsed === null) {
            $this->error('No YAML frontmatter block detected at the top of the document.');
            return self::FAILURE;
        }

        $validation = $parser->validate($parsed);
        if (! $validation->valid) {
            $this->error('Canonical validation failed:');
            foreach ($validation->errors as $field => $errors) {
                foreach ($errors as $err) {
                    $this->line("  - [{$field}] {$err}");
                }
            }
            return self::FAILURE;
        }

        if ($dryRun) {
            // Resolve the destination path the same way the real write
            // would, but without touching disk. Gives operators a
            // concrete preview of what --no-dry-run would produce.
            $folder = (string) (config('kb.promotion.path_conventions.' . ($parsed->type?->value ?? ''), '?'));
            $destination = $folder === '.' || $folder === ''
                ? ($parsed->slug . '.md')
                : (trim($folder, '/') . '/' . $parsed->slug . '.md');

            $this->info("[dry-run] Would write to project '{$projectKey}' as:");
            $this->line("  slug: {$parsed->slug}");
            $this->line("  type: {$parsed->type?->value}");
            $this->line("  status: {$parsed->status?->value}");
            $this->line("  destination: {$destination}");
            $this->line("  disk: " . (string) config('kb.sources.disk', 'kb'));
            return self::SUCCESS;
        }

        try {
            $relativePath = $writer->write($parsed, $markdown);
        } catch (\RuntimeException $e) {
            $this->error('Write failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        IngestDocumentJob::dispatch(
            projectKey: $projectKey,
            relativePath: $relativePath,
            disk: (string) config('kb.sources.disk', 'kb'),
            title: $parsed->slug ?? basename($path),
            metadata: [
                'disk' => (string) config('kb.sources.disk', 'kb'),
                'prefix' => (string) config('kb.sources.path_prefix', ''),
                'promotion_source' => 'cli',
            ],
        );

        $this->info("Promoted '{$parsed->slug}' to {$relativePath}");
        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\KnowledgeDocument;
use App\Services\Kb\Canonical\CanonicalParser;
use App\Support\KbPath;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Walk every canonical-ish document under the KB disk (or a subset) and
 * validate its frontmatter. Prints per-file errors so operators can fix
 * broken canonical docs before the next ingest. No DB writes.
 *
 * Two modes:
 *   - with `--from-disk`: iterates the KB disk (files under the conventional
 *     canonical folders), reads each .md, and validates. Use this after a
 *     bulk import from Git.
 *   - default (DB mode): iterates `knowledge_documents` flagged as
 *     is_canonical=true, revalidates their stored `frontmatter_json`.
 */
class KbValidateCanonicalCommand extends Command
{
    protected $signature = 'kb:validate-canonical
        {--project= : Limit to a single project_key}
        {--from-disk : Walk the KB disk instead of the DB}
        {--disk= : Override the KB disk (defaults to KB_FILESYSTEM_DISK)}';

    protected $description = 'Validate canonical markdown frontmatter — reports per-file schema errors.';

    public function handle(CanonicalParser $parser): int
    {
        $projectKey = (string) ($this->option('project') ?? '');
        $fromDisk = (bool) $this->option('from-disk');
        $disk = (string) ($this->option('disk') ?? config('kb.sources.disk', 'kb'));

        if ($fromDisk) {
            return $this->walkDisk($parser, $disk);
        }
        return $this->walkDatabase($parser, $projectKey);
    }

    private function walkDisk(CanonicalParser $parser, string $disk): int
    {
        $storage = Storage::disk($disk);
        $prefix = (string) config('kb.sources.path_prefix', '');
        $conventions = config('kb.promotion.path_conventions', []);

        $totalOk = 0;
        $totalErrors = 0;

        foreach ($conventions as $type => $folder) {
            $rel = $this->joinPath($prefix, (string) $folder);
            $files = $storage->files($rel);
            foreach ($files as $file) {
                if (! str_ends_with($file, '.md')) {
                    continue;
                }
                $content = (string) $storage->get($file);
                $verdict = $this->validateFile($parser, $file, $content);
                $verdict ? $totalOk++ : $totalErrors++;
            }
        }

        $this->line('');
        $this->info("Validated: {$totalOk} OK, {$totalErrors} error(s).");
        return $totalErrors === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function walkDatabase(CanonicalParser $parser, string $projectKey): int
    {
        $query = KnowledgeDocument::query()->where('is_canonical', true);
        if ($projectKey !== '') {
            $query->where('project_key', $projectKey);
        }

        $totalOk = 0;
        $totalErrors = 0;

        $query->orderBy('id')->chunkById(100, function ($docs) use ($parser, &$totalOk, &$totalErrors) {
            foreach ($docs as $doc) {
                $verdict = $this->validateDocumentRow($parser, $doc);
                $verdict ? $totalOk++ : $totalErrors++;
            }
        });

        $this->line('');
        $this->info("Validated: {$totalOk} OK, {$totalErrors} error(s).");
        return $totalErrors === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function validateFile(CanonicalParser $parser, string $path, string $content): bool
    {
        $parsed = $parser->parse($content);
        if ($parsed === null) {
            $this->warn("  ✗ {$path}  no frontmatter block");
            return false;
        }
        $validation = $parser->validate($parsed);
        if ($validation->valid) {
            $this->line("  ✓ {$path}");
            return true;
        }
        $this->warn("  ✗ {$path}");
        foreach ($validation->errors as $field => $errors) {
            foreach ($errors as $err) {
                $this->line("      [{$field}] {$err}");
            }
        }
        return false;
    }

    private function validateDocumentRow(CanonicalParser $parser, KnowledgeDocument $doc): bool
    {
        $fm = $doc->frontmatter_json;
        if (! is_array($fm)) {
            $this->warn("  ✗ [{$doc->id}] {$doc->source_path}  no frontmatter_json");
            return false;
        }
        // Reconstruct a minimal header from the stored frontmatter_json so
        // we can re-run CanonicalParser without re-reading the MD file.
        $yaml = \Symfony\Component\Yaml\Yaml::dump($this->stripDerived($fm));
        $synthetic = "---\n" . $yaml . "---\n\n";

        $parsed = $parser->parse($synthetic);
        if ($parsed === null) {
            $this->warn("  ✗ [{$doc->id}] {$doc->source_path}  could not re-parse frontmatter_json");
            return false;
        }
        $validation = $parser->validate($parsed);
        if ($validation->valid) {
            $this->line("  ✓ [{$doc->id}] {$doc->source_path}");
            return true;
        }
        $this->warn("  ✗ [{$doc->id}] {$doc->source_path}");
        foreach ($validation->errors as $field => $errors) {
            foreach ($errors as $err) {
                $this->line("      [{$field}] {$err}");
            }
        }
        return false;
    }

    /**
     * @param  array<string, mixed>  $fm
     * @return array<string, mixed>
     */
    private function stripDerived(array $fm): array
    {
        unset($fm['_derived']);
        return $fm;
    }

    private function joinPath(string $prefix, string $folder): string
    {
        $prefix = trim($prefix, '/');
        $folder = trim($folder, '/');
        if ($folder === '' || $folder === '.') {
            return $prefix;
        }
        if ($prefix === '') {
            return $folder;
        }
        return $prefix . '/' . $folder;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Models\KnowledgeDocument;
use App\Support\KbDocumentFileLocator;
use App\Support\KbPath;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * Build a ZIP of the markdown files backing a batch of
 * KnowledgeDocument rows (explorer multi-select → "Download ZIP").
 *
 * - Disk + path resolution goes through {@see KbDocumentFileLocator}
 *   so the exporter reads exactly the file the ingest side wrote
 *   (R1 / R8).
 * - Entry names are the normalised `source_path`, so the folder
 *   structure survives inside the archive and two docs sharing a
 *   basename in different folders can never collide.
 * - Missing / unreadable files are SKIPPED, logged (R4) and listed in
 *   a `manifest.json` entry inside the archive — partial failure is
 *   visible to the operator, the download still succeeds. When
 *   NOTHING is exportable the caller must answer 404, never an empty
 *   zip under 200 (R14): {@see ZipExportResult::$includedCount}.
 *
 * Markdown payloads are small (the editor caps writes at 2 MiB), so a
 * synchronous ZipArchive on a tempnam() file is deliberate — no async
 * job pipeline needed at this scale.
 */
class KbZipExporter
{
    /**
     * @param  Collection<int, KnowledgeDocument>  $documents
     * @param  list<int>  $missingIds  requested ids that resolved to no in-tenant row
     */
    public function export(Collection $documents, array $missingIds = []): ZipExportResult
    {
        $included = [];
        $skipped = [];
        $contents = [];

        foreach ($missingIds as $id) {
            $skipped[] = ['id' => $id, 'path' => null, 'reason' => 'not_found'];
        }

        foreach ($documents as $document) {
            $sourcePath = KbPath::normalize((string) $document->source_path);
            $disk = KbDocumentFileLocator::diskFor($document);
            $fullPath = KbDocumentFileLocator::fullPathFor($document, $sourcePath);
            $storage = Storage::disk($disk);

            if (! $storage->exists($fullPath)) {
                Log::warning('kb zip export: file missing on disk, skipping', [
                    'document_id' => $document->id,
                    'disk' => $disk,
                    'path' => $fullPath,
                ]);
                $skipped[] = ['id' => (int) $document->id, 'path' => $sourcePath, 'reason' => 'missing_on_disk'];
                continue;
            }

            $content = $storage->get($fullPath);
            if ($content === null) {
                // Storage::get returns null on read failure (R4).
                Log::warning('kb zip export: file unreadable, skipping', [
                    'document_id' => $document->id,
                    'disk' => $disk,
                    'path' => $fullPath,
                ]);
                $skipped[] = ['id' => (int) $document->id, 'path' => $sourcePath, 'reason' => 'unreadable'];
                continue;
            }

            $included[] = ['id' => (int) $document->id, 'path' => $sourcePath];
            $contents[$sourcePath] = $content;
        }

        if ($included === []) {
            return new ZipExportResult(null, 0, $skipped);
        }

        $manifest = [
            'generated_at' => Carbon::now()->toIso8601String(),
            'included' => $included,
            'skipped' => $skipped,
        ];

        return new ZipExportResult(
            $this->writeArchive($contents, $manifest),
            count($included),
            $skipped,
        );
    }

    /**
     * Write the archive to a temp file, checking EVERY ZipArchive
     * return value — a false from open()/addFromString()/close() must
     * surface as an exception, never as a truncated zip on 200 (R4).
     *
     * @param  array<string, string>  $contents  entry path => bytes
     * @param  array<string, mixed>  $manifest
     */
    private function writeArchive(array $contents, array $manifest): string
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'kb_zip_');
        if ($tmpPath === false) {
            throw new RuntimeException('Could not allocate a temp file for the ZIP export.');
        }

        $zip = new ZipArchive;
        $opened = $zip->open($tmpPath, ZipArchive::OVERWRITE);
        if ($opened !== true) {
            @unlink($tmpPath);
            throw new RuntimeException("Could not open ZIP archive (code {$opened}).");
        }

        try {
            foreach ($contents as $entryPath => $bytes) {
                if (! $zip->addFromString($entryPath, $bytes)) {
                    throw new RuntimeException("Could not add '{$entryPath}' to the ZIP archive.");
                }
            }

            $manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($manifestJson === false || ! $zip->addFromString('manifest.json', $manifestJson)) {
                throw new RuntimeException('Could not add manifest.json to the ZIP archive.');
            }

            if (! $zip->close()) {
                throw new RuntimeException('Could not finalise the ZIP archive.');
            }
        } catch (RuntimeException $e) {
            @unlink($tmpPath);
            throw $e;
        }

        return $tmpPath;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Kb\Canonical;

use App\Support\KbPath;
use Illuminate\Support\Facades\Storage;

/**
 * Writes canonical markdown to the KB disk.
 *
 * Single responsibility: given a validated {@see CanonicalParsedDocument}
 * and the original markdown, write the file to the conventional folder for
 * its type ({@see config}('kb.promotion.path_conventions')), respecting
 * the configured `KB_PATH_PREFIX`, and verify the write succeeded.
 *
 * Rule R4 (no silent failures): every `Storage::put()` return value is
 * checked and a failed write raises a `RuntimeException` so the caller
 * can surface a proper 5xx. The `@`-silenced / ignored-return pattern is
 * never acceptable in this path.
 */
class CanonicalWriter
{
    /**
     * Write the markdown to the KB disk and return the RELATIVE path
     * (from the tenant prefix, not from the disk root).
     *
     * @throws \InvalidArgumentException when the DTO lacks slug or type
     * @throws \RuntimeException         when no path convention exists or
     *                                   when the disk write reports failure
     */
    public function write(CanonicalParsedDocument $doc, string $originalMarkdown): string
    {
        $this->guardDocumentShape($doc);

        $relativePath = $this->relativePathFor($doc);
        $fullPath = $this->applyPathPrefix($relativePath);
        $disk = $this->resolveDisk();

        $written = Storage::disk($disk)->put($fullPath, $originalMarkdown);
        if ($written === false) {
            throw new \RuntimeException(
                "Failed to write canonical markdown to disk [{$disk}]: {$fullPath}"
            );
        }

        return $relativePath;
    }

    // -----------------------------------------------------------------
    // validation (bad paths first)
    // -----------------------------------------------------------------

    private function guardDocumentShape(CanonicalParsedDocument $doc): void
    {
        if ($doc->type === null) {
            throw new \InvalidArgumentException(
                'CanonicalWriter: document is missing a type. '
                .'Run CanonicalParser::validate() before calling write().'
            );
        }
        if ($doc->slug === null || $doc->slug === '') {
            throw new \InvalidArgumentException(
                'CanonicalWriter: document is missing a slug. '
                .'Run CanonicalParser::validate() before calling write().'
            );
        }
    }

    // -----------------------------------------------------------------
    // path resolution
    // -----------------------------------------------------------------

    private function relativePathFor(CanonicalParsedDocument $doc): string
    {
        $folder = $this->folderForType((string) $doc->type?->value);
        $filename = $doc->slug . '.md';

        if ($folder === '' || $folder === '.') {
            return $filename;
        }
        return trim($folder, '/') . '/' . $filename;
    }

    private function folderForType(string $type): string
    {
        $conventions = config('kb.promotion.path_conventions', []);
        if (! is_array($conventions) || ! array_key_exists($type, $conventions)) {
            throw new \RuntimeException(
                "CanonicalWriter: no path convention for canonical_type '{$type}'. "
                ."Add it to kb.promotion.path_conventions in config/kb.php."
            );
        }
        return (string) $conventions[$type];
    }

    /**
     * Normalize via {@see KbPath::normalize()} so the resulting key is
     * byte-identical to what the ingest read path expects. Without this, a
     * trailing-slash in KB_PATH_PREFIX would produce `foo//decisions/x.md`
     * on write while ingest reads `foo/decisions/x.md` — a silent miss on
     * S3 and other key-based disks (see R1).
     */
    private function applyPathPrefix(string $relativePath): string
    {
        $prefix = (string) config('kb.sources.path_prefix', '');
        if ($prefix === '') {
            return KbPath::normalize($relativePath);
        }
        return KbPath::normalize($prefix . '/' . $relativePath);
    }

    private function resolveDisk(): string
    {
        return (string) config('kb.sources.disk', 'kb');
    }
}

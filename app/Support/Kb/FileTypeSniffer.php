<?php

declare(strict_types=1);

namespace App\Support\Kb;

/**
 * Decoded-content type validation for ingested files (SEC-UPLOAD-001).
 *
 * The upload boundary previously trusted the file EXTENSION and the
 * CLIENT-PROVIDED MIME (`UploadedFile::getClientMimeType()`), both attacker-
 * controlled. This sniffer inspects the actual leading bytes so a file's real
 * content must be consistent with its declared {@see SourceType}:
 *
 *   - A file declared `pdf` must actually begin with the `%PDF-` signature.
 *   - A file declared `docx` must actually be a ZIP container (`PK\x03\x04`) —
 *     DOCX is an OOXML zip; we check the container rather than a fragile finfo
 *     MIME (finfo reports DOCX as `application/zip` on many builds).
 *   - A file declared `markdown`/`text` must NOT begin with a known BINARY
 *     signature (PDF/ZIP/PNG/JPEG/GIF/GZIP/ELF/PE) — i.e. a binary payload
 *     smuggled under a `.md`/`.txt` name is rejected.
 *
 * Text-to-text is intentionally permissive (markdown IS `text/plain`); we only
 * reject a declared-text file whose bytes are unmistakably binary.
 */
final class FileTypeSniffer
{
    /**
     * Known binary magic-number signatures, keyed by a human label. Used to
     * reject a binary payload masquerading as declared-text.
     *
     * @var array<string, string>
     */
    /**
     * ZIP local-file-header, empty-archive and spanned-archive signatures. DOCX
     * is an OOXML zip; a real one always has entries (PK\x03\x04) but we accept
     * the whole family so a valid edge-case archive is never falsely rejected.
     *
     * @var array<int, string>
     */
    private const ZIP_SIGNATURES = ["PK\x03\x04", "PK\x05\x06", "PK\x07\x08"];

    private const BINARY_SIGNATURES = [
        'pdf' => "%PDF-",
        'zip' => "PK\x03\x04",
        'png' => "\x89PNG\r\n\x1a\n",
        'jpeg' => "\xFF\xD8\xFF",
        'gif87' => 'GIF87a',
        'gif89' => 'GIF89a',
        'gzip' => "\x1F\x8B",
        'elf' => "\x7FELF",
        'pe' => 'MZ',
    ];

    /**
     * Returns a human-readable error when the file's real content does not
     * match its declared type, or null when the content is acceptable.
     */
    public static function mismatchReason(string $realPath, SourceType $declared): ?string
    {
        $head = self::readHead($realPath, 16);
        if ($head === null) {
            return 'file could not be read for type verification';
        }

        return match ($declared) {
            SourceType::PDF => str_starts_with($head, '%PDF-')
                ? null
                : 'declared as PDF but the content is not a PDF (missing %PDF- signature)',
            SourceType::DOCX => self::startsWithAny($head, self::ZIP_SIGNATURES)
                ? null
                : 'declared as DOCX but the content is not an OOXML/ZIP container',
            SourceType::MARKDOWN, SourceType::TEXT => self::binarySignatureIn($head),
            // Vendor/connector source types are produced server-side, not
            // uploaded as raw files, so there is nothing to sniff here.
            default => null,
        };
    }

    /**
     * @param  array<int, string>  $signatures
     */
    private static function startsWithAny(string $head, array $signatures): bool
    {
        foreach ($signatures as $signature) {
            if (str_starts_with($head, $signature)) {
                return true;
            }
        }

        return false;
    }

    private static function binarySignatureIn(string $head): ?string
    {
        foreach (self::BINARY_SIGNATURES as $label => $signature) {
            if (str_starts_with($head, $signature)) {
                return "declared as text/markdown but the content is a {$label} binary";
            }
        }

        // A leading NUL byte in the first chunk is a strong binary signal for a
        // file that claims to be UTF-8/ASCII text.
        if (str_contains($head, "\0")) {
            return 'declared as text/markdown but the content contains NUL bytes (binary)';
        }

        return null;
    }

    private static function readHead(string $realPath, int $bytes): ?string
    {
        if ($realPath === '' || ! is_readable($realPath)) {
            return null;
        }

        $handle = fopen($realPath, 'rb');
        if ($handle === false) {
            return null;
        }

        try {
            $head = fread($handle, $bytes);
        } finally {
            fclose($handle);
        }

        return $head === false ? null : $head;
    }
}

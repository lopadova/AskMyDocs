<?php

declare(strict_types=1);

namespace App\Connectors\BuiltIn\Evernote;

/**
 * v4.5/W4 — Immutable outcome of a `.enex` bulk import.
 *
 * Returned by {@see EnexImporter::import()} so the controller can shape
 * the HTTP response without leaking internal types.
 */
final class EnexImportResult
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(
        public readonly int $imported,
        public readonly int $skipped,
        public readonly array $errors,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'imported' => $this->imported,
            'skipped' => $this->skipped,
            'errors' => $this->errors,
        ];
    }
}

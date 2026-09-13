<?php

declare(strict_types=1);

namespace App\Services\Kb\Versioning;

/**
 * v8.36 / ADR 0030 — the effective source-retention mode (ADR 0014
 * foundation, wired here for the first time). One global knob today
 * (`KB_SOURCE_RETENTION`); an unknown value is not a permissive one — it
 * resolves to `full_copy`, the mode that keeps everything.
 */
final class SourceRetentionResolver
{
    public const FULL_COPY = 'full_copy';

    public const MARKDOWN_ONLY = 'markdown_only';

    public const REFERENCE_ONLY = 'reference_only';

    public const MODES = [self::FULL_COPY, self::MARKDOWN_ONLY, self::REFERENCE_ONLY];

    public function mode(): string
    {
        $mode = strtolower(trim((string) config('kb.source_retention.mode', self::FULL_COPY)));

        return in_array($mode, self::MODES, true) ? $mode : self::FULL_COPY;
    }

    /** Whether the effective mode stores the converted Markdown as an artifact. */
    public function retainsMarkdown(): bool
    {
        return $this->mode() !== self::REFERENCE_ONLY;
    }

    /** Whether the effective mode drops the original binary once the artifact is committed. */
    public function dropsOriginal(): bool
    {
        return $this->mode() === self::MARKDOWN_ONLY;
    }
}

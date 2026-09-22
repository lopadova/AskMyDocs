<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Concerns;

/**
 * PR #492 Copilot round-8 — an MCP tool `schema()` declaring
 * `$schema->integer()` does not, by itself, guarantee the argument that
 * actually arrives IS an integer: a client is free to send `"1.5"`, and PHP
 * silently truncates `(int) "1.5"` to `1` rather than refusing it — the
 * handler then looks up (and answers for) a document nobody asked about.
 * `KbDocumentVersionsTool` already closed this gap for `document_id`,
 * `limit` and `offset`; extracted here so every MCP tool taking an id/count
 * argument gets the identical strict check, not a re-derived one that can
 * drift from it.
 */
trait ValidatesIntegerArgument
{
    /**
     * An argument as an ACTUAL integer: PHP int, or a string of digits with
     * an optional sign — null when absent, false when it is anything else
     * (a float, scientific notation, padding, an array).
     */
    private static function integerArgument(mixed $value): int|null|false
    {
        if ($value === null) {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[+-]?\d+$/', $value) === 1) {
            return (int) $value;
        }

        return false;
    }
}

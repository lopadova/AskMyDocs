<?php

declare(strict_types=1);

namespace App\Services\Kb\Code;

/**
 * Static extension ⇄ language ⇄ MIME map for the code content class
 * (v8.45 / W5, ADR 0035 D7).
 *
 * Pure data, no container access: the enum {@see \App\Support\Kb\SourceType}
 * and the entry points call it, and it stays unit-testable. The MIME of a code
 * file is the synthetic token `text/x-<language>` — the same convention the
 * connector vendor MIMEs use to route a source to its converter and chunker
 * without inventing a new dispatch surface. `config/kb-pipeline.php`
 * `mime_to_source_type` lists the same tokens (a parity test pins both).
 */
final class CodeLanguageMap
{
    /** @var array<string, string> lower-case extension → language token */
    public const EXTENSIONS = [
        'php' => 'php',
        'ts' => 'typescript',
        'tsx' => 'tsx',
        'js' => 'javascript',
        'jsx' => 'javascript',
        'mjs' => 'javascript',
        'cjs' => 'javascript',
        'py' => 'python',
        'go' => 'go',
        'rs' => 'rust',
        'java' => 'java',
        'cs' => 'csharp',
        'rb' => 'ruby',
        'sql' => 'sql',
        'yml' => 'yaml',
        'yaml' => 'yaml',
        'json' => 'json',
        'sh' => 'bash',
    ];

    /**
     * Languages the tree-sitter helper ships a grammar for. The rest (sql,
     * yaml, json) are served by the heuristic driver by design — a language
     * without a pinned grammar is moved to the heuristic, not dropped.
     *
     * @var list<string>
     */
    public const AST_LANGUAGES = [
        'php', 'typescript', 'tsx', 'javascript', 'python', 'go', 'rust', 'java', 'csharp', 'ruby', 'bash',
    ];

    public const MIME_PREFIX = 'text/x-';

    /**
     * @return list<string> every extension, lower-case, no dot
     */
    public static function extensions(): array
    {
        return array_keys(self::EXTENSIONS);
    }

    /**
     * @return list<string> every language token, de-duplicated
     */
    public static function languages(): array
    {
        return array_values(array_unique(array_values(self::EXTENSIONS)));
    }

    /**
     * @return list<string> the `text/x-<language>` MIME tokens
     */
    public static function mimes(): array
    {
        return array_map(static fn (string $l): string => self::MIME_PREFIX.$l, self::languages());
    }

    public static function languageForExtension(string $extension): ?string
    {
        return self::EXTENSIONS[strtolower(ltrim($extension, '.'))] ?? null;
    }

    public static function languageForPath(string $path): ?string
    {
        return self::languageForExtension((string) pathinfo($path, PATHINFO_EXTENSION));
    }

    public static function languageForMime(string $mime): ?string
    {
        $normalised = strtolower(trim(explode(';', $mime, 2)[0]));
        if (! str_starts_with($normalised, self::MIME_PREFIX)) {
            return null;
        }
        $language = substr($normalised, strlen(self::MIME_PREFIX));

        return in_array($language, self::languages(), true) ? $language : null;
    }

    public static function mimeForLanguage(string $language): string
    {
        return self::MIME_PREFIX.$language;
    }

    public static function mimeForPath(string $path): ?string
    {
        $language = self::languageForPath($path);

        return $language === null ? null : self::mimeForLanguage($language);
    }

    public static function isAstCapable(string $language): bool
    {
        return in_array($language, self::AST_LANGUAGES, true);
    }
}

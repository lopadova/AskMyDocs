<?php

declare(strict_types=1);

namespace App\Services\Kb\Code;

/**
 * Admission policy for source files (v8.45 / W5, ADR 0035 D7) — decided BEFORE
 * a byte is stored, converted or embedded.
 *
 * Four independent checks, cheapest first:
 *  1. the master switch `kb.code.ingestion_enabled` (R43 — the shipped default
 *     is OFF and the OFF path is byte-identical to v8.44);
 *  2. a known code extension;
 *  3. the NON-OVERRIDABLE path deny-list — dependency trees, build output,
 *     VCS internals, minified bundles, lockfiles and credential material.
 *     `kb.code.extra_deny_globs` can ADD patterns, never remove one;
 *  4. content checks — size cap, binary (NUL) content and the generated-file
 *     heuristic (header marker such as `@generated` / `DO NOT EDIT`).
 *
 * The deny-list is a security control (a `.env`, a private key or a
 * `vendor/` tree must never reach the vector store), which is why it has no
 * off-switch and why it also guards the paths that bypass the entry points
 * (it is re-applied by the converter's caller, not trusted from the client).
 */
final class CodeIngestionPolicy
{
    /** Directory segments refused anywhere in the path (case-insensitive). */
    private const DENIED_SEGMENTS = ['vendor', 'node_modules', '.git', 'dist', 'build', 'storage'];

    /** fnmatch globs matched against the BASENAME (case-insensitive). */
    private const DENIED_BASENAME_GLOBS = [
        '*.min.*',
        '.env', '.env.*', '*.env',
        '*.pem', '*.key', '*.p12', '*.pfx', '*.jks', '*.keystore',
        'id_rsa*', 'id_dsa*', 'id_ecdsa*', 'id_ed25519*',
        '*.lock', 'package-lock.json', 'npm-shrinkwrap.json', 'pnpm-lock.yaml', 'yarn.lock',
        'composer.lock', 'Gemfile.lock', 'Cargo.lock', 'poetry.lock', 'go.sum',
    ];

    /** Header comment markers that flag a generated file. */
    private const GENERATED_PATTERNS = [
        '/@generated\b/i',
        '/\bDO NOT EDIT\b/i',
        '/\bauto-?generated\b/i',
        '/\bthis file (?:is|was) (?:automatically |auto-)?generated\b/i',
        '/\bcode generated\b/i',
    ];

    private const HEADER_LINES = 15;

    public function enabled(): bool
    {
        return (bool) config('kb.code.ingestion_enabled', false);
    }

    public function maxFileBytes(): int
    {
        return max(1024, (int) config('kb.code.max_file_bytes', 524288));
    }

    /**
     * Path-level admission (extension + deny-list + optional known size). Used
     * where the bytes are not at hand yet (listing a folder walk).
     */
    public function assessPath(string $path, ?int $sizeBytes = null, bool $checkEnabled = true): CodeVerdict
    {
        if ($checkEnabled && ! $this->enabled()) {
            return CodeVerdict::refuse(CodeVerdict::DISABLED);
        }
        if (CodeLanguageMap::languageForPath($path) === null) {
            return CodeVerdict::refuse(CodeVerdict::UNSUPPORTED, strtolower((string) pathinfo($path, PATHINFO_EXTENSION)));
        }
        $denied = $this->deniedBy($path);
        if ($denied !== null) {
            return CodeVerdict::refuse(CodeVerdict::DENIED_PATH, $denied);
        }
        if ($sizeBytes !== null && $sizeBytes > $this->maxFileBytes()) {
            return CodeVerdict::refuse(CodeVerdict::TOO_LARGE, $sizeBytes.' > '.$this->maxFileBytes());
        }

        return CodeVerdict::accept();
    }

    /**
     * Full admission once the bytes are known: the path checks plus size,
     * binary content and the generated-file heuristic.
     *
     * `$checkEnabled = false` is for a job ALREADY queued when the operator
     * flipped the flag off: the master switch is an entry-point gate, but the
     * deny-list, size, binary and generated checks still apply to every byte.
     */
    public function assessContent(string $path, string $bytes, bool $checkEnabled = true): CodeVerdict
    {
        $verdict = $this->assessPath($path, strlen($bytes), $checkEnabled);
        if (! $verdict->accepted) {
            return $verdict;
        }
        if (str_contains(substr($bytes, 0, 8192), "\0")) {
            return CodeVerdict::refuse(CodeVerdict::BINARY);
        }
        if ($this->looksGenerated($bytes)) {
            return CodeVerdict::refuse(CodeVerdict::GENERATED);
        }

        return CodeVerdict::accept();
    }

    /**
     * Which deny rule matches the path, or null. Pure (no flag check), so the
     * deny-list can be unit-tested and reused as a defence in depth.
     */
    public function deniedBy(string $path): ?string
    {
        $normalised = strtolower(str_replace('\\', '/', trim($path, '/')));
        $segments = array_values(array_filter(explode('/', $normalised), static fn (string $s): bool => $s !== ''));
        if ($segments === []) {
            return null;
        }
        $basename = (string) end($segments);
        $directories = array_slice($segments, 0, -1);

        foreach ($directories as $segment) {
            if (in_array($segment, self::DENIED_SEGMENTS, true)) {
                return $segment.'/';
            }
        }
        foreach (self::DENIED_BASENAME_GLOBS as $glob) {
            if (fnmatch(strtolower($glob), $basename)) {
                return $glob;
            }
        }
        foreach ($this->extraGlobs() as $glob) {
            $target = str_contains($glob, '/') ? $normalised : $basename;
            if (fnmatch(strtolower($glob), $target, FNM_PATHNAME)) {
                return $glob;
            }
        }

        return null;
    }

    public function looksGenerated(string $bytes): bool
    {
        $header = implode("\n", array_slice(preg_split('/\R/', substr($bytes, 0, 4096)) ?: [], 0, self::HEADER_LINES));
        foreach (preg_split('/\R/', $header) ?: [] as $line) {
            $trimmed = ltrim($line);
            // Only comment lines count: prose such as `$autoGenerated = true;`
            // must not exclude a hand-written file.
            if ($trimmed === '' || ! preg_match('~^(?://|#|/\*|\*|--|<!--|;)~', $trimmed)) {
                continue;
            }
            foreach (self::GENERATED_PATTERNS as $pattern) {
                if (preg_match($pattern, $trimmed) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function extraGlobs(): array
    {
        $configured = config('kb.code.extra_deny_globs', []);
        if (! is_array($configured)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn ($g): string => trim((string) $g), $configured),
            static fn (string $g): bool => $g !== '',
        ));
    }
}

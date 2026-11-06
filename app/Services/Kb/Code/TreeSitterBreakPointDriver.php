<?php

declare(strict_types=1);

namespace App\Services\Kb\Code;

use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Break points from a real syntax tree, computed OUT OF PROCESS (ADR 0035 D7).
 *
 * The parser (`web-tree-sitter`, WASM) lives in a Node helper —
 * `tools/code-chunker/index.mjs` — so a malformed file can never crash a PHP
 * queue worker, grammars need no C toolchain at deploy, and the kill switch is
 * a process timeout. The helper receives the source on STDIN and prints JSON
 * break points only (positions and symbol names, never code).
 *
 * The process is built from an ARGUMENT ARRAY — there is no shell and no
 * string interpolation, and the only variable argument (the language token) is
 * checked against the static {@see CodeLanguageMap::AST_LANGUAGES} allow-list
 * (architecture test `CodeProcessArgvTest` pins "no string command").
 *
 * Every failure surfaces as a {@see CodeChunkerUnavailableException} with a
 * stable reason code; the resolver decides between falling back (auto) and
 * failing the ingest (forced).
 */
final class TreeSitterBreakPointDriver implements BreakPointDriver
{
    private const PROBE_CACHE_KEY = 'kb:code:tree_sitter_probe';
    private const PROBE_TIMEOUT_SECONDS = 8;

    /** @var array{ok: bool, reason: string|null, grammars: list<string>}|null */
    private static ?array $probeMemo = null;

    public function name(): string
    {
        return 'tree_sitter';
    }

    public function nodeBinary(): string
    {
        $configured = trim((string) config('kb.code.node_binary', 'node'));

        return $configured !== '' ? $configured : 'node';
    }

    public function helperPath(): string
    {
        return (string) config('kb.code.helper_path', base_path('tools/code-chunker/index.mjs'));
    }

    /**
     * Health probe: is Node present, is the helper installed (its
     * `node_modules` resolved) and which grammars does it answer for?
     * Cached per process and for `kb.code.probe_ttl` seconds in the cache
     * store, so a worker does not spawn a probe per file.
     *
     * @return array{ok: bool, reason: string|null, grammars: list<string>}
     */
    public function probe(bool $fresh = false): array
    {
        if ($fresh) {
            self::forgetProbe();
        }
        if (self::$probeMemo !== null) {
            return self::$probeMemo;
        }
        $ttl = max(0, (int) config('kb.code.probe_ttl', 300));
        try {
            $cached = $ttl > 0 ? Cache::get(self::PROBE_CACHE_KEY) : null;
            if (is_array($cached) && array_key_exists('ok', $cached)) {
                return self::$probeMemo = $cached;
            }
        } catch (Throwable) {
            // A broken cache store must not break ingestion: probe live.
        }

        $result = $this->runProbe();
        try {
            if ($ttl > 0) {
                Cache::put(self::PROBE_CACHE_KEY, $result, $ttl);
            }
        } catch (Throwable) {
            // best effort
        }

        return self::$probeMemo = $result;
    }

    public static function forgetProbe(): void
    {
        self::$probeMemo = null;
        try {
            Cache::forget(self::PROBE_CACHE_KEY);
        } catch (Throwable) {
            // best effort
        }
    }

    public function isAvailable(string $language): bool
    {
        if (! CodeLanguageMap::isAstCapable($language)) {
            return false;
        }
        $probe = $this->probe();

        return $probe['ok'] && in_array($language, $probe['grammars'], true);
    }

    public function breakPoints(string $source, string $language): array
    {
        if (! CodeLanguageMap::isAstCapable($language)) {
            throw new CodeChunkerUnavailableException('language_not_supported');
        }
        $probe = $this->probe();
        if (! $probe['ok']) {
            throw new CodeChunkerUnavailableException((string) ($probe['reason'] ?? 'helper_unavailable'));
        }
        if (! in_array($language, $probe['grammars'], true)) {
            throw new CodeChunkerUnavailableException('grammar_unavailable');
        }

        $process = $this->process(['--language', $language]);
        $process->setInput($source);
        $process->setTimeout(max(1, (int) config('kb.code.parse_timeout', 10)));

        $maxOutput = max(1024, (int) config('kb.code.max_helper_output_bytes', 1048576));
        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            throw new CodeChunkerUnavailableException('timeout');
        } catch (Throwable) {
            throw new CodeChunkerUnavailableException('process_error');
        }
        if (! $process->isSuccessful()) {
            throw new CodeChunkerUnavailableException('exit_'.(int) $process->getExitCode());
        }
        $output = $process->getOutput();
        if (strlen($output) > $maxOutput) {
            throw new CodeChunkerUnavailableException('output_too_large');
        }

        return $this->parse($output);
    }

    /**
     * @return list<BreakPoint>
     */
    private function parse(string $output): array
    {
        $decoded = json_decode($output, true);
        if (! is_array($decoded) || ($decoded['ok'] ?? false) !== true || ! is_array($decoded['breakpoints'] ?? null)) {
            throw new CodeChunkerUnavailableException('malformed_output');
        }

        $points = [];
        foreach ($decoded['breakpoints'] as $bp) {
            if (! is_array($bp) || ! is_int($bp['line'] ?? null) || $bp['line'] < 1) {
                throw new CodeChunkerUnavailableException('malformed_output');
            }
            $symbol = $bp['symbol'] ?? null;
            $points[] = new BreakPoint(
                line: (int) $bp['line'],
                endLine: is_int($bp['end_line'] ?? null) ? max((int) $bp['end_line'], (int) $bp['line']) : (int) $bp['line'],
                score: is_int($bp['score'] ?? null) ? max(0, min(100, (int) $bp['score'])) : 0,
                type: is_string($bp['type'] ?? null) ? $bp['type'] : 'statement',
                symbol: is_string($symbol) && $symbol !== '' ? $symbol : null,
                depth: is_int($bp['depth'] ?? null) ? max(0, (int) $bp['depth']) : 0,
            );
        }
        usort($points, static fn (BreakPoint $a, BreakPoint $b): int => $a->line <=> $b->line);

        return $points;
    }

    /**
     * @return array{ok: bool, reason: string|null, grammars: list<string>}
     */
    private function runProbe(): array
    {
        $helper = $this->helperPath();
        if (! is_file($helper)) {
            return ['ok' => false, 'reason' => 'helper_missing', 'grammars' => []];
        }
        $process = $this->process(['--probe']);
        $process->setTimeout(self::PROBE_TIMEOUT_SECONDS);
        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return ['ok' => false, 'reason' => 'probe_timeout', 'grammars' => []];
        } catch (Throwable) {
            return ['ok' => false, 'reason' => 'node_unavailable', 'grammars' => []];
        }
        if (! $process->isSuccessful()) {
            // Exit 127 / spawn error = no Node; anything else = helper dependencies missing.
            return ['ok' => false, 'reason' => $process->getExitCode() === 127 ? 'node_unavailable' : 'helper_dependencies_missing', 'grammars' => []];
        }
        $decoded = json_decode($process->getOutput(), true);
        if (! is_array($decoded) || ($decoded['ok'] ?? false) !== true || ! is_array($decoded['grammars'] ?? null)) {
            return ['ok' => false, 'reason' => 'probe_malformed', 'grammars' => []];
        }
        $grammars = array_values(array_filter(
            array_map('strval', $decoded['grammars']),
            static fn (string $g): bool => CodeLanguageMap::isAstCapable($g),
        ));

        return ['ok' => $grammars !== [], 'reason' => $grammars === [] ? 'no_grammars' : null, 'grammars' => $grammars];
    }

    /**
     * ARGUMENT ARRAY only — no shell, no interpolation.
     *
     * @param  list<string>  $arguments
     */
    private function process(array $arguments): Process
    {
        $helper = $this->helperPath();
        $process = new Process([$this->nodeBinary(), $helper, ...$arguments], dirname($helper));
        $process->setEnv(['NODE_OPTIONS' => '--max-old-space-size=256', 'NODE_NO_WARNINGS' => '1']);

        return $process;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * ADR 0035 D7 / SEC-SHELL-001 — the code-ingestion subsystem spawns exactly
 * one kind of process (the Node tree-sitter helper) and it is ALWAYS built
 * from an argument ARRAY. A string command would route source-derived or
 * operator-supplied text through a shell; there is no legitimate reason for it
 * here, so the build fails if one ever appears under app/Services/Kb/Code or
 * the code converter/chunker.
 */
final class CodeProcessArgvTest extends TestCase
{
    /** @return list<string> */
    private function files(): array
    {
        $root = dirname(__DIR__, 2).'/app/Services/Kb';
        $files = glob($root.'/Code/*.php') ?: [];
        foreach (['Converters/CodePassthroughConverter.php', 'Chunkers/CodeAstChunker.php'] as $extra) {
            $files[] = $root.'/'.$extra;
        }

        return $files;
    }

    public function test_the_subsystem_exists(): void
    {
        $this->assertGreaterThanOrEqual(8, count($this->files()));
        foreach ($this->files() as $file) {
            $this->assertFileExists($file);
        }
    }

    public function test_every_process_is_built_from_an_argument_array(): void
    {
        $constructions = 0;
        foreach ($this->files() as $file) {
            $code = (string) file_get_contents($file);
            // `new Process(` must be followed by `[` — an array, never a string.
            preg_match_all('/new\s+Process\(\s*(.)/', $code, $m);
            foreach ($m[1] as $firstChar) {
                $constructions++;
                $this->assertSame('[', $firstChar, basename($file).': Process must be constructed from an argument array');
            }
        }
        $this->assertGreaterThanOrEqual(1, $constructions, 'the helper driver builds a Process');
    }

    public function test_no_shell_execution_primitives_are_used(): void
    {
        $forbidden = ['shell_exec', 'exec', 'passthru', 'system', 'popen', 'proc_open', 'pcntl_exec', 'fromShellCommandline'];
        foreach ($this->files() as $file) {
            // Token scan: comments and string literals are separate tokens, so
            // prose in a docblock or a regex containing a backtick cannot trip it.
            $tokens = token_get_all((string) file_get_contents($file));
            foreach ($tokens as $i => $token) {
                $this->assertNotSame('`', $token, basename($file).' must not use the backtick shell operator');
                if (! is_array($token) || $token[0] !== T_STRING || ! in_array($token[1], $forbidden, true)) {
                    continue;
                }
                $next = $tokens[$i + 1] ?? null;
                $isCall = $next === '(' || (is_array($next) && $next[0] === T_WHITESPACE && ($tokens[$i + 2] ?? null) === '(');
                $this->assertFalse($isCall, basename($file).' must not call '.$token[1].'()');
            }
        }
    }

    public function test_the_only_variable_helper_argument_is_checked_against_the_language_allow_list(): void
    {
        $code = (string) file_get_contents(dirname(__DIR__, 2).'/app/Services/Kb/Code/TreeSitterBreakPointDriver.php');

        // breakPoints() refuses a language outside the static allow-list BEFORE
        // it reaches process().
        $this->assertMatchesRegularExpression('/isAstCapable\(\$language\)\)\s*\{\s*throw/s', $code);
        $this->assertMatchesRegularExpression("/process\(\['--language', \\\$language\]\)/", $code);
    }
}

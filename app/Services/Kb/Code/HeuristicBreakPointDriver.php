<?php

declare(strict_types=1);

namespace App\Services\Kb\Code;

/**
 * Pure-PHP break-point driver: language-aware line patterns for top-level
 * declarations, with a brace/indent-based end line and a symbol path built
 * from the enclosing declaration. It needs no process and no grammar, so it
 * is the always-available floor of the degradation ladder and the permanent
 * driver for languages without a pinned grammar (sql, yaml, json).
 *
 * Structurally better than the markdown chunker for code even though it is not
 * a parser: declaration starts (plus their leading doc comments / attributes)
 * become break points, so a chunk begins on a boundary instead of mid-function.
 */
final class HeuristicBreakPointDriver implements BreakPointDriver
{
    private const CONTAINER_SCORE = 100;
    private const FUNCTION_SCORE = 90;
    private const MEMBER_SCORE = 80;
    private const IMPORT_SCORE = 60;

    /**
     * Per-language `[kind => regex]`. Group `n` captures the symbol name.
     * `kind`: class | function | import | type.
     *
     * @var array<string, list<array{string, string}>>
     */
    private const PATTERNS = [
        'php' => [
            ['class', '/^\s*(?:(?:abstract|final|readonly)\s+)*(?:class|interface|trait|enum)\s+(?<n>[A-Za-z_]\w*)/'],
            ['function', '/^\s*(?:(?:public|protected|private|static|abstract|final)\s+)*function\s+&?(?<n>[A-Za-z_]\w*)\s*\(/'],
            ['import', '/^\s*(?:use\s+[A-Za-z_\\\\]|namespace\s+|require(?:_once)?\b|include(?:_once)?\b)/'],
        ],
        'typescript' => [
            ['class', '/^\s*(?:export\s+)?(?:default\s+)?(?:abstract\s+)?(?:class|interface|enum|namespace)\s+(?<n>[A-Za-z_$][\w$]*)/'],
            ['function', '/^\s*(?:export\s+)?(?:default\s+)?(?:async\s+)?function\s*\*?\s*(?<n>[A-Za-z_$][\w$]*)/'],
            ['function', '/^\s*(?:export\s+)?(?:const|let|var)\s+(?<n>[A-Za-z_$][\w$]*)\s*(?::[^=]+)?=\s*(?:async\s*)?(?:\([^)]*\)|[A-Za-z_$][\w$]*)\s*(?::[^=]+)?=>/'],
            ['type', '/^\s*(?:export\s+)?type\s+(?<n>[A-Za-z_$][\w$]*)\s*[=<]/'],
            ['import', '/^\s*import\s/'],
        ],
        'javascript' => [
            ['class', '/^\s*(?:export\s+)?(?:default\s+)?class\s+(?<n>[A-Za-z_$][\w$]*)/'],
            ['function', '/^\s*(?:export\s+)?(?:default\s+)?(?:async\s+)?function\s*\*?\s*(?<n>[A-Za-z_$][\w$]*)/'],
            ['function', '/^\s*(?:export\s+)?(?:const|let|var)\s+(?<n>[A-Za-z_$][\w$]*)\s*=\s*(?:async\s*)?(?:\([^)]*\)|[A-Za-z_$][\w$]*)\s*=>/'],
            ['import', '/^\s*(?:import\s|const\s+\w+\s*=\s*require\()/'],
        ],
        'python' => [
            ['class', '/^\s*class\s+(?<n>[A-Za-z_]\w*)/'],
            ['function', '/^\s*(?:async\s+)?def\s+(?<n>[A-Za-z_]\w*)\s*\(/'],
            ['import', '/^\s*(?:import\s|from\s+\S+\s+import\s)/'],
        ],
        'go' => [
            ['type', '/^\s*type\s+(?<n>[A-Za-z_]\w*)\s+(?:struct|interface)\b/'],
            ['function', '/^\s*func\s+(?:\([^)]*\)\s*)?(?<n>[A-Za-z_]\w*)\s*[\[(]/'],
            ['import', '/^\s*(?:import\s|package\s)/'],
        ],
        'rust' => [
            ['class', '/^\s*(?:pub(?:\([^)]*\))?\s+)?(?:struct|enum|trait|impl|mod)\s*(?:<[^>]*>\s*)?(?<n>[A-Za-z_]\w*)/'],
            ['function', '/^\s*(?:pub(?:\([^)]*\))?\s+)?(?:async\s+)?(?:unsafe\s+)?fn\s+(?<n>[A-Za-z_]\w*)/'],
            ['import', '/^\s*(?:use\s|extern\s+crate\s)/'],
        ],
        'java' => [
            ['class', '/^\s*(?:(?:public|protected|private|abstract|final|static|sealed)\s+)*(?:class|interface|enum|record)\s+(?<n>[A-Za-z_]\w*)/'],
            ['function', '/^\s*(?:(?:public|protected|private|static|final|abstract|synchronized)\s+)+[\w<>\[\], ?]+\s+(?<n>[A-Za-z_]\w*)\s*\([^;]*$/'],
            ['import', '/^\s*(?:import\s|package\s)/'],
        ],
        'csharp' => [
            ['class', '/^\s*(?:(?:public|internal|protected|private|abstract|sealed|static|partial)\s+)*(?:class|interface|struct|enum|record)\s+(?<n>[A-Za-z_]\w*)/'],
            ['function', '/^\s*(?:(?:public|internal|protected|private|static|virtual|override|async|abstract)\s+)+[\w<>\[\], ?]+\s+(?<n>[A-Za-z_]\w*)\s*\([^;]*$/'],
            ['import', '/^\s*using\s+[A-Za-z_]/'],
        ],
        'ruby' => [
            ['class', '/^\s*(?:class|module)\s+(?<n>[A-Z]\w*(?:::\w+)*)/'],
            ['function', '/^\s*def\s+(?:self\.)?(?<n>[A-Za-z_]\w*[?!=]?)/'],
            ['import', '/^\s*require(?:_relative)?\s/'],
        ],
        'bash' => [
            ['function', '/^\s*(?:function\s+)?(?<n>[A-Za-z_][\w\-]*)\s*\(\)\s*\{?/'],
        ],
        'sql' => [
            ['class', '/^\s*create\s+(?:or\s+replace\s+)?(?:temp(?:orary)?\s+)?(?:table|view|materialized\s+view|index|type)\s+(?:if\s+not\s+exists\s+)?(?<n>[\w."`]+)/i'],
            ['function', '/^\s*create\s+(?:or\s+replace\s+)?(?:function|procedure|trigger)\s+(?<n>[\w."`]+)/i'],
        ],
        'yaml' => [
            ['class', '/^(?<n>[A-Za-z0-9_.\-]+):\s*(?:#.*)?$/'],
        ],
        'json' => [
            ['class', '/^  "(?<n>[^"]+)"\s*:/'],
        ],
    ];

    /** Languages whose blocks end by indentation rather than braces. */
    private const INDENT_LANGUAGES = ['python', 'ruby', 'yaml'];

    public function name(): string
    {
        return 'heuristic';
    }

    public function breakPoints(string $source, string $language): array
    {
        // TSX shares TypeScript's declaration syntax.
        $patterns = self::PATTERNS[$language === 'tsx' ? 'typescript' : $language] ?? [];
        if ($patterns === []) {
            return [];
        }
        $lines = preg_split('/\R/', $source) ?: [];
        $count = count($lines);

        $found = [];
        $inImportRun = false;
        $stack = []; // list<array{name:string, indent:int}>
        for ($i = 0; $i < $count; $i++) {
            $line = $lines[$i];
            if (trim($line) === '') {
                continue;
            }
            $indent = $this->indentOf($line);
            // Pop containers this line has left (indent-sensitive languages and
            // brace languages alike use the indent of the closing/next line).
            while ($stack !== [] && $indent <= $stack[array_key_last($stack)]['indent'] && ! $this->isClosingLine($line)) {
                array_pop($stack);
            }

            $match = $this->match($patterns, $line);
            if ($match === null) {
                $inImportRun = false;

                continue;
            }
            [$kind, $name] = $match;

            if ($kind === 'import') {
                if (! $inImportRun && $stack === []) {
                    $found[] = ['line' => $i, 'kind' => 'imports', 'name' => null, 'indent' => $indent];
                }
                $inImportRun = true;

                continue;
            }
            $inImportRun = false;

            // yaml/json: only top-level keys are structure.
            if (in_array($language, ['yaml', 'json'], true) && $indent > ($language === 'json' ? 2 : 0)) {
                continue;
            }
            $found[] = ['line' => $i, 'kind' => $kind, 'name' => $name, 'indent' => $indent, 'depth' => count($stack)];
            if (in_array($kind, ['class', 'type'], true) && $language !== 'sql') {
                $stack[] = ['name' => (string) $name, 'indent' => $indent];
            }
        }

        return $this->toBreakPoints($found, $lines, $language);
    }

    /**
     * @param  list<array{string, string}>  $patterns
     * @return array{0: string, 1: string|null}|null
     */
    private function match(array $patterns, string $line): ?array
    {
        foreach ($patterns as [$kind, $regex]) {
            if (preg_match($regex, $line, $m) === 1) {
                return [$kind, isset($m['n']) ? trim((string) $m['n'], '"`') : null];
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $found
     * @param  list<string>  $lines
     * @return list<BreakPoint>
     */
    private function toBreakPoints(array $found, array $lines, string $language): array
    {
        $points = [];
        $stackNames = [];
        $stackIndents = [];
        foreach ($found as $f) {
            $idx = (int) $f['line'];
            $indent = (int) $f['indent'];
            while ($stackIndents !== [] && $indent <= end($stackIndents)) {
                array_pop($stackIndents);
                array_pop($stackNames);
            }
            $kind = (string) $f['kind'];
            $start = $this->withLeading($lines, $idx);
            $end = $this->endLine($lines, $idx, $language);

            if ($kind === 'imports') {
                $points[] = new BreakPoint($start + 1, $end + 1, self::IMPORT_SCORE, 'imports', null, 0);

                continue;
            }
            $name = (string) ($f['name'] ?? '');
            $path = implode(' > ', array_filter([...$stackNames, $name], static fn (string $s): bool => $s !== ''));
            $depth = count($stackNames);
            $isContainer = in_array($kind, ['class', 'type'], true);
            $points[] = new BreakPoint(
                line: $start + 1,
                endLine: $end + 1,
                score: $isContainer && $depth === 0 ? self::CONTAINER_SCORE : ($depth === 0 ? self::FUNCTION_SCORE : self::MEMBER_SCORE),
                type: $isContainer ? ($kind === 'type' ? 'type' : 'class') : ($depth === 0 ? 'function' : 'method'),
                symbol: $path !== '' ? $path : null,
                depth: $depth,
            );
            if ($isContainer && $language !== 'sql') {
                $stackNames[] = $name;
                $stackIndents[] = $indent;
            }
        }

        // One break point per line, highest score wins; ascending order.
        $byLine = [];
        foreach ($points as $p) {
            if (! isset($byLine[$p->line]) || $p->score > $byLine[$p->line]->score) {
                $byLine[$p->line] = $p;
            }
        }
        ksort($byLine);

        return array_values($byLine);
    }

    /** Pull the start upward over adjacent doc comments / attributes / decorators. */
    private function withLeading(array $lines, int $idx): int
    {
        $start = $idx;
        while ($start > 0) {
            $prev = trim((string) $lines[$start - 1]);
            if ($prev === '') {
                break;
            }
            if (preg_match('~^(?://|#|/\*|\*|\*/|--|@|\[|<!--)~', $prev) !== 1) {
                break;
            }
            $start--;
        }

        return $start;
    }

    private function endLine(array $lines, int $idx, string $language): int
    {
        $count = count($lines);
        if (in_array($language, self::INDENT_LANGUAGES, true)) {
            $indent = $this->indentOf((string) $lines[$idx]);
            $end = $idx;
            for ($i = $idx + 1; $i < $count; $i++) {
                if (trim((string) $lines[$i]) === '') {
                    continue;
                }
                if ($this->indentOf((string) $lines[$i]) <= $indent && ! ($language === 'ruby' && preg_match('/^\s*end\b/', (string) $lines[$i]) === 1)) {
                    break;
                }
                $end = $i;
            }

            return $end;
        }

        $depth = 0;
        $seen = false;
        for ($i = $idx; $i < $count; $i++) {
            $stripped = preg_replace('/(["\'`])(?:\\\\.|(?!\1).)*\1/', '', (string) $lines[$i]) ?? (string) $lines[$i];
            $opens = substr_count($stripped, '{');
            $closes = substr_count($stripped, '}');
            $depth += $opens - $closes;
            $seen = $seen || $opens > 0;
            if ($seen && $depth <= 0) {
                return $i;
            }
            // A body-less declaration (interface method, `fn x();`, SQL) ends at `;`.
            if (! $seen && str_contains($stripped, ';')) {
                return $i;
            }
        }

        return $count - 1;
    }

    private function indentOf(string $line): int
    {
        return strlen($line) - strlen(ltrim($line, " \t"));
    }

    private function isClosingLine(string $line): bool
    {
        return preg_match('/^\s*[})\]]/', $line) === 1;
    }
}

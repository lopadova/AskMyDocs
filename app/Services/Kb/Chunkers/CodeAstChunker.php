<?php

declare(strict_types=1);

namespace App\Services\Kb\Chunkers;

use App\Services\Kb\Chunking\Support\TextBudgetSplitter;
use App\Services\Kb\Chunking\Support\TokenCounter;
use App\Services\Kb\Code\BreakPoint;
use App\Services\Kb\Code\BreakPointDriverResolver;
use App\Services\Kb\Code\CodeLanguageMap;
use App\Services\Kb\Contracts\ChunkerInterface;
use App\Services\Kb\Pipeline\ChunkDraft;
use App\Services\Kb\Pipeline\ConvertedDocument;
use RuntimeException;

/**
 * Structure-aware chunker for source code (v8.45 / W5, ADR 0035 D7).
 *
 * Input is the single fenced block {@see \App\Services\Kb\Converters\CodePassthroughConverter}
 * produces. Break points come from a driver (tree-sitter helper or the PHP
 * heuristic) and are scored by structure: class/interface 100, top-level
 * function 90, method 80, import block 60, top-level statement 40; the chunker
 * adds blank lines (20).
 *
 * Algorithm (deterministic — same input ⇒ same drafts, R23):
 *  1. Fill a chunk greedily up to `kb.chunking.target_tokens`.
 *  2. If a declaration STRADDLES that boundary and fits the hard cap together
 *     with the chunk start, extend the chunk to keep the declaration whole
 *     ("never splits a node smaller than the hard cap").
 *  3. Otherwise cut at the best break point inside a look-back window (the last
 *     35 % of the chunk), each scored `score × (1 − 0.7 × d²)` where `d` is its
 *     normalised distance from the greedy end (squared-distance decay: a high
 *     structural score wins over a slightly earlier blank line, but a far one
 *     does not).
 *  4. No candidate at all (a single huge function) ⇒ hard cut at the greedy
 *     end, with `kb.chunking.overlap_tokens` of tail overlap.
 *  5. A tiny trailing chunk is merged into its predecessor when it fits the cap.
 *
 * Chunk text is the RAW code slice (no fence): cleaner for full-text search,
 * embeddings and secret/PII passes. `heading_path` is the file path followed
 * by the symbol path (`src/Billing/Invoice.php > Invoice > total`).
 */
final class CodeAstChunker implements ChunkerInterface
{
    public const SUPPORTED_SOURCE_TYPES = ['code'];

    private const TARGET_TOKENS_DEFAULT = 512;
    private const HARD_CAP_DEFAULT = 1024;
    private const OVERLAP_DEFAULT = 64;
    private const WINDOW_FRACTION = 0.35;
    private const DECAY = 0.7;
    private const BLANK_LINE_SCORE = 20;
    private const MAX_SYMBOLS = 12;
    private const HEADING_MAX_CHARS = 250;

    /** Mirrors {@see TokenCounter}'s 4-chars-per-token estimate when turning a token cap into bytes. */
    private const BYTES_PER_TOKEN = 4;

    private TokenCounter $tokens;

    private TextBudgetSplitter $splitter;

    public function __construct(private readonly BreakPointDriverResolver $drivers, ?TokenCounter $tokens = null, ?TextBudgetSplitter $splitter = null)
    {
        $this->tokens = $tokens ?? new TokenCounter();
        $this->splitter = $splitter ?? new TextBudgetSplitter();
    }

    public function name(): string
    {
        return 'code-ast-chunker';
    }

    public function supports(string $sourceType): bool
    {
        return in_array($sourceType, self::SUPPORTED_SOURCE_TYPES, true);
    }

    /**
     * @return list<ChunkDraft>
     */
    public function chunk(ConvertedDocument $doc): array
    {
        $source = $this->unfence($doc->markdown);
        if ($source === null || trim($source) === '') {
            return [];
        }
        $language = (string) ($doc->extractionMeta['language'] ?? '');
        if ($language === '') {
            $language = CodeLanguageMap::languageForMime($doc->sourceMimeType) ?? '';
        }
        if ($language === '') {
            // R14 — a code chunker with no language is a routing bug, not a
            // document to index as prose.
            throw new RuntimeException('CodeAstChunker: no language on the converted document.');
        }
        $filePath = (string) ($doc->extractionMeta['source_path'] ?? $doc->extractionMeta['filename'] ?? '');

        $lines = explode("\n", $source);
        $result = $this->drivers->breakPoints($source, $language);
        $points = $result['points'];

        $ranges = $this->ranges($lines, $points);
        $hardCap = max(
            $this->config('kb.chunking.target_tokens', self::TARGET_TOKENS_DEFAULT),
            $this->config('kb.chunking.hard_cap_tokens', self::HARD_CAP_DEFAULT),
        );
        $drafts = [];
        $order = 0;
        // The converter's redaction count rides on the FIRST chunk only, so the
        // sum over a document's chunks equals what was redacted (ingestion
        // status counts it; never the values).
        $secretsRedacted = max(0, (int) ($doc->extractionMeta['secrets_redacted'] ?? 0));
        foreach ($ranges as [$start, $end]) {
            $slice = array_slice($lines, $start, $end - $start);
            $text = implode("\n", $slice);
            if (trim($text) === '') {
                continue;
            }
            [$symbol, $symbols] = $this->symbolsFor($points, $start, $end);
            $metadata = [
                'filename' => (string) ($doc->extractionMeta['filename'] ?? basename($filePath)),
                'source_type' => 'code',
                'strategy' => 'code-ast',
                'chunker' => $result['driver'],
                'language' => $language,
                'symbol' => $symbol,
                'symbols' => $symbols,
                'start_line' => $start + 1,
                'end_line' => $end,
            ];
            if ($drafts === [] && $secretsRedacted > 0) {
                $metadata['secrets_redacted'] = $secretsRedacted;
            }
            // The range builder never cuts INSIDE a line, so a single line over
            // the hard cap (a minified bundle, an inlined data blob) would still
            // become one oversized chunk. Split exactly that case on the shared
            // byte budget — UTF-8 safe, no text dropped — and say so in metadata.
            $pieces = $this->tokens->estimate($text) > $hardCap
                ? $this->splitter->split($text, $hardCap * self::BYTES_PER_TOKEN)
                : [$text];
            foreach ($pieces as $part => $piece) {
                $drafts[] = new ChunkDraft(
                    text: $piece,
                    order: $order++,
                    headingPath: $this->headingPath($filePath, $symbol),
                    metadata: count($pieces) > 1
                        ? $metadata + ['split' => ['part' => $part + 1, 'of' => count($pieces)]]
                        : $metadata,
                );
                unset($metadata['secrets_redacted']);
            }
        }

        return $drafts;
    }

    /**
     * @param  list<string>  $lines
     * @param  list<BreakPoint>  $points
     * @return list<array{int, int}> half-open [startIdx, endIdx) line ranges
     */
    private function ranges(array $lines, array $points): array
    {
        $n = count($lines);
        $target = $this->config('kb.chunking.target_tokens', self::TARGET_TOKENS_DEFAULT);
        $hardCap = max($target, $this->config('kb.chunking.hard_cap_tokens', self::HARD_CAP_DEFAULT));
        $overlapConfigured = config('kb.chunking.overlap_tokens', self::OVERLAP_DEFAULT);
        $overlap = is_numeric($overlapConfigured) ? max(0, (int) $overlapConfigured) : self::OVERLAP_DEFAULT; // 0 = off

        // prefix[i] = tokens of lines [0, i)
        $prefix = [0];
        foreach ($lines as $i => $line) {
            $prefix[$i + 1] = $prefix[$i] + max(1, $this->tokens->estimate($line."\n"));
        }
        $tokensOf = static fn (int $a, int $b): int => $prefix[$b] - $prefix[$a];

        // Candidate cut positions: index of the line a NEW chunk may start at.
        $candidates = [];
        foreach ($points as $bp) {
            $idx = $bp->line - 1;
            if ($idx > 0 && $idx < $n) {
                $candidates[$idx] = max($candidates[$idx] ?? 0, $bp->score);
            }
        }
        for ($i = 1; $i < $n; $i++) {
            if (trim($lines[$i - 1]) === '' && trim($lines[$i]) !== '') {
                $candidates[$i] = max($candidates[$i] ?? 0, self::BLANK_LINE_SCORE);
            }
        }

        $declarations = array_values(array_filter($points, static fn (BreakPoint $p): bool => $p->isDeclaration()));

        $ranges = [];
        $start = 0;
        while ($start < $n) {
            // 1. Greedy fill up to the target.
            $end = $start;
            while ($end < $n && $tokensOf($start, $end + 1) <= $target) {
                $end++;
            }
            if ($end === $start) {
                $end = $start + 1; // one line larger than the target
            }
            if ($end >= $n) {
                $ranges[] = [$start, $n];
                break;
            }

            // 2. Keep a straddling declaration whole when it fits the hard cap.
            $extended = null;
            foreach ($declarations as $d) {
                $dStart = $d->line - 1;
                $dEnd = min($n - 1, $d->endLine - 1);
                if ($dStart < $end && $dEnd >= $end && $dStart >= $start && $tokensOf($start, $dEnd + 1) <= $hardCap) {
                    $extended = $dEnd + 1;

                    break;
                }
            }
            if ($extended !== null) {
                $ranges[] = [$start, $extended];
                $start = $extended;

                continue;
            }

            // 3. Best break point in the look-back window.
            $span = $end - $start;
            $windowStart = max($start + 1, $end - (int) ceil($span * self::WINDOW_FRACTION));
            $best = null;
            $bestScore = -1.0;
            for ($c = $windowStart; $c <= $end; $c++) {
                if (! isset($candidates[$c])) {
                    continue;
                }
                $distance = ($end - $c) / max(1, $end - $windowStart);
                $adjusted = $candidates[$c] * (1 - self::DECAY * $distance * $distance);
                if ($adjusted >= $bestScore) { // ties → the later (larger) chunk
                    $bestScore = $adjusted;
                    $best = $c;
                }
            }

            if ($best !== null) {
                $ranges[] = [$start, $best];
                $start = $best;

                continue;
            }

            // 4. Hard cut inside a node, with tail overlap.
            $ranges[] = [$start, $end];
            $next = $end;
            if ($overlap > 0) {
                $back = $end;
                while ($back > $start + 1 && $tokensOf($back - 1, $end) <= $overlap) {
                    $back--;
                }
                $next = max($start + 1, $back);
            }
            $start = $next;
        }

        // 5. Merge a tiny trailing chunk into its predecessor.
        $count = count($ranges);
        if ($count >= 2) {
            [$lastStart, $lastEnd] = $ranges[$count - 1];
            [$prevStart] = $ranges[$count - 2];
            $minTokens = min(64, intdiv($target, 4));
            if ($tokensOf($lastStart, $lastEnd) < $minTokens && $tokensOf($prevStart, $lastEnd) <= $hardCap) {
                array_pop($ranges);
                $ranges[$count - 2] = [$prevStart, $lastEnd];
            }
        }

        return $ranges;
    }

    /**
     * The chunk's primary symbol and the list of symbols it contains.
     *
     * @param  list<BreakPoint>  $points
     * @return array{0: string|null, 1: list<string>}
     */
    private function symbolsFor(array $points, int $start, int $end): array
    {
        $inside = [];
        $enclosing = null;
        foreach ($points as $bp) {
            if (! $bp->isDeclaration() || $bp->symbol === null) {
                continue;
            }
            $dStart = $bp->line - 1;
            $dEnd = $bp->endLine - 1;
            if ($dStart >= $start && $dStart < $end) {
                $inside[] = $bp->symbol;
            } elseif ($dStart < $start && $dEnd >= $start) {
                $enclosing = $bp->symbol; // the last one wins = the deepest
            }
        }
        $symbols = array_slice(array_values(array_unique($inside)), 0, self::MAX_SYMBOLS);
        $primary = $inside[0] ?? $enclosing;
        if ($primary !== null && $inside === []) {
            $symbols = [$primary];
        }

        return [$primary, $symbols];
    }

    private function headingPath(string $filePath, ?string $symbol): string
    {
        $path = $filePath;
        if ($symbol !== null && $symbol !== '') {
            $path = ($filePath !== '' ? $filePath.' > ' : '').$symbol;
        }

        return mb_strlen($path) > self::HEADING_MAX_CHARS ? mb_substr($path, 0, self::HEADING_MAX_CHARS) : $path;
    }

    /** Strip the single wrapping code fence the converter emits. */
    private function unfence(string $markdown): ?string
    {
        $markdown = rtrim($markdown, "\n");
        if ($markdown === '') {
            return null;
        }
        $lines = explode("\n", $markdown);
        if (preg_match('/^(`{3,})[A-Za-z0-9_+\-]*$/', $lines[0], $open) !== 1) {
            return null;
        }
        $closing = $lines[count($lines) - 1];
        if ($closing !== $open[1] || count($lines) < 2) {
            return null;
        }

        return implode("\n", array_slice($lines, 1, -1));
    }

    private function config(string $key, int $default): int
    {
        $value = config($key, $default);

        return is_numeric($value) && (int) $value > 0 ? (int) $value : $default;
    }
}

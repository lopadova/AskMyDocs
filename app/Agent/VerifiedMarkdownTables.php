<?php

declare(strict_types=1);

namespace App\Agent;

/** Add only empty headers/separators to orphan table rows; never invent column labels or values. */
final class VerifiedMarkdownTables
{
    public function normalize(string $text, bool $mergeRepeatedHeaders = true): string
    {
        $lines = preg_split('/\r\n|\n|\r/', $text);
        $output = [];
        $columns = null;
        $header = null;
        $fence = null;
        $skip = -1;
        foreach ($lines as $index => $line) {
            if ($index <= $skip) continue;
            if ($fence !== null) {
                if (preg_match('/^ {0,3}'.preg_quote($fence[0], '/').'{'.strlen($fence).',}[ \t]*$/', $line)) $fence = null;
                $output[] = $line;
                continue;
            }
            if (preg_match('/^ {0,3}(`{3,}|~{3,})/', $line, $match)) {
                $fence = $match[1];
                $columns = null;
                $header = null;
                $output[] = $line;
                continue;
            }
            $cells = $this->cells($line);
            if (trim($line) === '' && $columns !== null) {
                $nextIndex = $index + 1;
                while (isset($lines[$nextIndex]) && trim($lines[$nextIndex]) === '') $nextIndex++;
                if (isset($lines[$nextIndex]) && str_starts_with(ltrim($lines[$nextIndex]), '|')
                    && count($this->cells($lines[$nextIndex])) === $columns) continue;
            }
            $next = $this->cells($lines[$index + 1] ?? '');
            if ((count($cells) > 1 || str_starts_with(ltrim($line), '|'))
                && count($next) === count($cells) && $this->separator($next)) {
                if ($mergeRepeatedHeaders && $columns === count($cells) && $header === $cells) {
                    $skip = $index + 1;
                    continue;
                }
                if ($columns !== null && $output !== [] && trim(end($output)) !== '') $output[] = '';
                $columns = count($cells);
                $header = $cells;
                $output[] = $line;
                $output[] = $lines[$index + 1];
                $skip = $index + 1;
                continue;
            }
            if ($columns === count($cells) && str_starts_with(ltrim($line), '|')) {
                $output[] = $line;
                continue;
            }
            if (count($cells) < 2 && ! (str_starts_with(trim($line), '|') && str_ends_with(trim($line), '|'))) {
                $columns = null;
                $header = null;
                $output[] = $line;
                continue;
            }
            if (! $this->separator($cells) && str_starts_with(trim($line), '|')) {
                $columns = count($cells);
                $header = array_fill(0, $columns, '');
                if ($output !== [] && trim(end($output)) !== '') $output[] = '';
                $output[] = '|'.str_repeat(' |', $columns);
                $output[] = '|'.str_repeat('---|', $columns);
            } else {
                $columns = null;
                $header = null;
            }
            $output[] = $line;
        }
        return implode(str_contains($text, "\r\n") ? "\r\n" : "\n", $output);
    }

    public function hasTable(string $text): bool
    {
        $lines = preg_split('/\r\n|\n|\r/', $text);
        $fence = null;
        foreach ($lines as $index => $line) {
            if ($fence !== null) {
                if (preg_match('/^ {0,3}'.preg_quote($fence[0], '/').'{'.strlen($fence).',}[ \t]*$/', $line)) $fence = null;
                continue;
            }
            if (preg_match('/^ {0,3}(`{3,}|~{3,})/', $line, $match)) { $fence = $match[1]; continue; }
            $cells = $this->cells($line);
            $next = $this->cells($lines[$index + 1] ?? '');
            if ((count($cells) > 1 || str_starts_with(ltrim($line), '|'))
                && count($cells) === count($next) && $this->separator($next)) return true;
        }
        return false;
    }

    private function cells(string $line): array
    {
        $line = trim($line);
        if (str_starts_with($line, '|')) $line = substr($line, 1);
        if (str_ends_with($line, '|') && ! str_ends_with($line, '\\|')) $line = substr($line, 0, -1);
        $cells = [];
        $cell = '';
        $escaped = false;
        foreach (str_split($line) as $character) {
            if ($character === '|' && ! $escaped) { $cells[] = trim($cell); $cell = ''; }
            else $cell .= $character;
            $escaped = $character === '\\' && ! $escaped;
        }
        $cells[] = trim($cell);
        return $cells;
    }

    private function separator(array $cells): bool
    {
        return $cells !== [] && ! array_filter($cells, fn ($cell) => preg_match('/^:?-{3,}:?$/', $cell) !== 1);
    }
}

<?php

declare(strict_types=1);

namespace App\Agent;

/** Add only empty headers/separators to orphan table rows; never invent column labels or values. */
final class VerifiedMarkdownTables
{
    public function normalize(string $text): string
    {
        $lines = preg_split('/\r\n|\n|\r/', $text);
        $output = [];
        $columns = null;
        $fence = null;
        foreach ($lines as $index => $line) {
            if ($fence !== null) {
                if (preg_match('/^ {0,3}'.preg_quote($fence[0], '/').'{'.strlen($fence).',}[ \t]*$/', $line)) $fence = null;
                $output[] = $line;
                continue;
            }
            if (preg_match('/^ {0,3}(`{3,}|~{3,})/', $line, $match)) {
                $fence = $match[1];
                $columns = null;
                $output[] = $line;
                continue;
            }
            $cells = $this->cells($line);
            if (count($cells) < 2) {
                $columns = null;
                $output[] = $line;
                continue;
            }
            if ($columns === count($cells)) {
                $output[] = $line;
                continue;
            }
            $next = $this->cells($lines[$index + 1] ?? '');
            if (count($next) === count($cells) && $this->separator($next)) {
                $columns = count($cells);
            } elseif (! $this->separator($cells) && str_starts_with(trim($line), '|') && str_ends_with(trim($line), '|')) {
                $columns = count($cells);
                if ($output !== [] && trim(end($output)) !== '') $output[] = '';
                $output[] = '|'.str_repeat(' |', $columns);
                $output[] = '|'.str_repeat('---|', $columns);
            } else {
                $columns = null;
            }
            $output[] = $line;
        }
        return implode(str_contains($text, "\r\n") ? "\r\n" : "\n", $output);
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

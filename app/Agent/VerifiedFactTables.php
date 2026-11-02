<?php

declare(strict_types=1);

namespace App\Agent;

/** Reuse only a validated layout's fact grouping, never its rejected wording. */
final class VerifiedFactTables
{
    public function render(array $paragraphs, array $claims, string $locale): ?array
    {
        if (! array_filter($paragraphs, fn ($p) => app(VerifiedMarkdownTables::class)->hasTable($p['text']))) return null;
        $result = [];
        $row = 0;
        foreach ($paragraphs as $paragraph) {
            if (($paragraph['kind'] ?? 'facts') !== 'facts') continue;
            $texts = array_map(fn ($id) => $claims[$id]['text'], $paragraph['claim_ids']);
            if (preg_match('/^\s*\|/m', $paragraph['text'])) {
                $header = str_starts_with($locale, 'it') ? '| Voce | Fatti verificati |' : '| Entry | Verified facts |';
                $cell = implode('<br><br>', array_map(static fn ($text) => str_replace(
                    ["\r\n", "\r", "\n", '|'], ['<br>', '<br>', '<br>', '\\|'],
                    htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8')), $texts));
                $paragraph['text'] = $header."\n|---|---|\n| ".(++$row).' | '.$cell.' |';
                $paragraph['literal_table'] = true;
            } else {
                $paragraph['text'] = implode("\n\n", $texts);
            }
            $paragraph['causal_links'] = [];
            $result[] = $paragraph;
        }
        return $result;
    }
}

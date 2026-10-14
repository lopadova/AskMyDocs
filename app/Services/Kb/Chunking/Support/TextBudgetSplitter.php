<?php

declare(strict_types=1);

namespace App\Services\Kb\Chunking\Support;

/** Split a single oversized block without dropping text or breaking UTF-8. */
final class TextBudgetSplitter
{
    /** @return list<string> */
    public function split(string $text, int $maxBytes): array
    {
        $maxBytes = max(4, $maxBytes);
        $pieces = [];
        while (strlen($text) > $maxBytes) {
            $head = mb_strcut($text, 0, $maxBytes, 'UTF-8');
            // Prefer a word/row boundary, but also handle long unbroken values.
            if (preg_match('/\s+\S*$/u', $head, $match, PREG_OFFSET_CAPTURE) === 1
                && $match[0][1] > intdiv($maxBytes, 2)) {
                $head = substr($head, 0, $match[0][1] + strlen($match[0][0]) - strlen(ltrim($match[0][0])));
            }
            $pieces[] = $head;
            $text = substr($text, strlen($head));
        }
        if ($text !== '') {
            $pieces[] = $text;
        }

        return $pieces;
    }
}

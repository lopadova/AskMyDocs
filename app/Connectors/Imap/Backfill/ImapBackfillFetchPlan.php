<?php

declare(strict_types=1);

namespace App\Connectors\Imap\Backfill;

/** Preserve checkpoint order while bounding both message count and raw bytes. */
final class ImapBackfillFetchPlan
{
    /**
     * @param list<int> $uids
     * @param array<int,int>|null $sizes
     * @return list<list<int>>
     */
    public static function chunks(array $uids, ?array $sizes, int $maxMessages, int $maxBytes): array
    {
        $maxMessages = max(1, $maxMessages);
        $maxBytes = max(1, $maxBytes);
        $chunks = [];
        $chunk = [];
        $bytes = 0;
        foreach ($uids as $uid) {
            // An unknown or oversized message gets its own fetch. Missing size
            // metadata must never make a large batch look artificially small.
            $size = isset($sizes[$uid]) && is_int($sizes[$uid]) && $sizes[$uid] >= 0
                ? $sizes[$uid] : $maxBytes;
            if ($chunk !== [] && (count($chunk) >= $maxMessages || $bytes + $size > $maxBytes)) {
                $chunks[] = $chunk;
                $chunk = [];
                $bytes = 0;
            }
            $chunk[] = $uid;
            $bytes += $size;
            if ($bytes >= $maxBytes) {
                $chunks[] = $chunk;
                $chunk = [];
                $bytes = 0;
            }
        }
        if ($chunk !== []) {
            $chunks[] = $chunk;
        }

        return $chunks;
    }
}

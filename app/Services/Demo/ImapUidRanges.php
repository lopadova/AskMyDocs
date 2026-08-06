<?php

declare(strict_types=1);

namespace App\Services\Demo;

/**
 * Compresses a bounded page of IMAP UIDs into safe contiguous STORE ranges.
 */
final class ImapUidRanges
{
    /**
     * @param  list<int>  $uids
     * @return list<array{0: int, 1: int}>
     */
    public static function compress(array $uids): array
    {
        if ($uids === []) {
            return [];
        }

        sort($uids, SORT_NUMERIC);
        $ranges = [];
        $from = $uids[0];
        $to = $from;

        foreach (array_slice($uids, 1) as $uid) {
            if ($uid === $to + 1) {
                $to = $uid;

                continue;
            }
            if ($uid === $to) {
                continue;
            }

            $ranges[] = [$from, $to];
            $from = $uid;
            $to = $uid;
        }

        $ranges[] = [$from, $to];

        return $ranges;
    }

    /**
     * @param  list<array{0: int, 1: int}>  $ranges
     */
    public static function sequenceSet(array $ranges): string
    {
        return implode(',', array_map(
            static fn (array $range): string => $range[0] === $range[1]
                ? (string) $range[0]
                : "{$range[0]}:{$range[1]}",
            $ranges,
        ));
    }
}

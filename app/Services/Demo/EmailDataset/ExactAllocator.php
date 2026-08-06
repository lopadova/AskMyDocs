<?php

declare(strict_types=1);

namespace App\Services\Demo\EmailDataset;

use InvalidArgumentException;

final class ExactAllocator
{
    /**
     * Largest-remainder allocation with stable lexical tie-breaking.
     *
     * @param  array<string, int|float>  $weights
     * @return array<string, int>
     */
    public function allocate(int $total, array $weights): array
    {
        if ($total < 0 || $weights === []) {
            throw new InvalidArgumentException('Allocation requires a non-negative total and at least one weight.');
        }

        ksort($weights, SORT_STRING);
        $weightTotal = array_sum($weights);
        if ($weightTotal <= 0) {
            throw new InvalidArgumentException('Allocation weights must have a positive sum.');
        }

        $allocated = [];
        $remainders = [];
        $used = 0;

        foreach ($weights as $key => $weight) {
            if ($weight < 0) {
                throw new InvalidArgumentException("Allocation weight {$key} must not be negative.");
            }

            $exact = $total * ((float) $weight / (float) $weightTotal);
            $floor = (int) floor($exact);
            $allocated[$key] = $floor;
            $remainders[$key] = $exact - $floor;
            $used += $floor;
        }

        $keys = array_keys($weights);
        usort($keys, static function (string $left, string $right) use ($remainders): int {
            $byRemainder = $remainders[$right] <=> $remainders[$left];

            return $byRemainder !== 0 ? $byRemainder : strcmp($left, $right);
        });

        for ($i = 0; $i < $total - $used; $i++) {
            $allocated[$keys[$i % count($keys)]]++;
        }

        ksort($allocated, SORT_STRING);

        return $allocated;
    }
}

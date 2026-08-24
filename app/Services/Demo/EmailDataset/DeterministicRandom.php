<?php

declare(strict_types=1);

namespace App\Services\Demo\EmailDataset;

use InvalidArgumentException;

/**
 * Hash-based PRNG: every draw is addressed by a stable scope, so results do
 * not depend on global mt_rand state or incidental filesystem iteration.
 */
final readonly class DeterministicRandom
{
    public function __construct(private int $seed) {}

    public function integer(string $scope, int $min, int $max): int
    {
        if ($max < $min) {
            throw new InvalidArgumentException('Random integer max must be greater than or equal to min.');
        }

        $range = $max - $min + 1;
        $bytes = substr(hash('sha256', $this->seed.'|'.$scope, true), 0, 4);
        $value = unpack('Nvalue', $bytes);

        return $min + ((int) $value['value'] % $range);
    }

    /**
     * @template T
     *
     * @param  list<T>  $values
     * @return T
     */
    public function pick(string $scope, array $values): mixed
    {
        if ($values === []) {
            throw new InvalidArgumentException('Cannot pick from an empty list.');
        }

        return $values[$this->integer($scope, 0, count($values) - 1)];
    }
}

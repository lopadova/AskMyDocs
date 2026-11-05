<?php

declare(strict_types=1);

namespace App\Services\Kb\Retrieval\Expansion\Learning;

use RuntimeException;

/** W7 — the training dataset could not be written (R4). HTTP maps exactly this type to 503. */
final class TrainingDatasetStorageFailed extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The training dataset could not be written to the configured disk.');
    }
}

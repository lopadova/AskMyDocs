<?php

declare(strict_types=1);

namespace App\Services\Kb\Retrieval\Expansion\Learning;

use RuntimeException;

/** W7 — a mutating learning-loop call while `KB_EXPANSION_LEARNING_ENABLED` is OFF (HTTP maps it to 404, R43). */
final class ExpansionLearningDisabled extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The expansion learning loop is disabled on this deployment (KB_EXPANSION_LEARNING_ENABLED).');
    }
}

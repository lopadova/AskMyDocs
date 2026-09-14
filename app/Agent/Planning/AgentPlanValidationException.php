<?php

declare(strict_types=1);

namespace App\Agent\Planning;

final class AgentPlanValidationException extends \UnexpectedValueException
{
    public function __construct(public readonly string $validationCode, string $message)
    {
        parent::__construct($message);
    }
}

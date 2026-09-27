<?php

namespace App\Services;

use RuntimeException;
use Ui4\Workbench\Contracts\WorkbenchAuthorizer;

final class Ui4WorkbenchAuthorizer implements WorkbenchAuthorizer
{
    private const ABILITIES = ['read', 'create', 'update', 'archive', 'link', 'confirm'];

    public function __construct(
        private readonly Ui4WorkbenchScope $scopes,
    ) {}

    public function can(string $actor, string $ability, ?string $type = null, ?string $recordId = null): bool
    {
        if (! in_array($ability, self::ABILITIES, true)) {
            return false;
        }

        try {
            $this->scopes->requireCurrent($actor, request());
        } catch (RuntimeException) {
            return false;
        }

        return true;
    }
}

<?php

namespace App\Services;

use Ui4\Workbench\Contracts\WorkbenchChannelAuthorizer;

final class Ui4WorkbenchChannelAuthorizer implements WorkbenchChannelAuthorizer
{
    public function __construct(private readonly Ui4WorkbenchScope $scopes) {}

    public function can(object $user, string $scope): bool
    {
        return hash_equals($this->scopes->forUser($user)['scope'], $scope);
    }
}

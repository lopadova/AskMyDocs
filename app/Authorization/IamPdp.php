<?php

declare(strict_types=1);

namespace App\Authorization;

use App\Models\User;

interface IamPdp
{
    public function decide(User $user, string $resource, string $action, mixed $subject = null): bool;
}

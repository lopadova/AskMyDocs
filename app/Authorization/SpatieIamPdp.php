<?php

declare(strict_types=1);

namespace App\Authorization;

use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class SpatieIamPdp implements IamPdp
{
    public function decide(User $user, string $resource, string $action, mixed $subject = null): bool
    {
        $definition = config("iam-shadow.resources.{$resource}.actions.{$action}");
        if (! is_array($definition)) {
            return false;
        }

        if (isset($definition['ability']) && $subject !== null) {
            return Gate::forUser($user)->allows((string) $definition['ability'], $subject);
        }

        if (isset($definition['permission'])) {
            return $user->can((string) $definition['permission']);
        }

        return false;
    }
}

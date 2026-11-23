<?php

declare(strict_types=1);

namespace App\Pact\Native;

use Illuminate\Contracts\Auth\Authenticatable;
use PactLaravel\Contracts\IdentityResolver;

final class SubjectResolver implements IdentityResolver
{
    public function current(): ?Authenticatable
    {
        return request()->attributes->get(Subject::class);
    }

    public function find(string $id): ?Authenticatable
    {
        if (filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            return null;
        }
        $subject = Subject::query()->find($id);

        return $subject?->authorizedUser() !== null ? $subject : null;
    }

    public function id(Authenticatable $user): string
    {
        return $user instanceof Subject ? (string) $user->getKey() : '';
    }
}

<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Enforces the global email identity across rolling schema deployments.
 *
 * Before email_normalized exists, the legacy email unique index is
 * case-sensitive on PostgreSQL and SQLite. The shared model scope closes that
 * window with LOWER(email), while still using the indexed normalized column
 * after the additive migration lands.
 */
final readonly class UniqueUserEmailIdentity implements ValidationRule
{
    public function __construct(private int|string|null $ignoreUserId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $query = User::withTrashed()->whereEmailIdentity((string) $value);

        if ($this->ignoreUserId !== null) {
            $query->whereKeyNot($this->ignoreUserId);
        }

        if ($query->exists()) {
            $fail('validation.unique')->translate(['attribute' => $attribute]);
        }
    }
}

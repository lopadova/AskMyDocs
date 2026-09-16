<?php

declare(strict_types=1);

namespace App\Support\Chat;

/**
 * Which slice of a user's sessions a listing wants.
 *
 * Archived threads are excluded SERVER-SIDE by default, so "archived"
 * means archived on every surface — including the older /chat sidebar,
 * which inherits the behaviour without changing a line. The archived
 * drawer asks for them explicitly.
 */
enum ConversationArchiveScope: string
{
    case Active = 'active';
    case Archived = 'archived';
    case All = 'all';

    /**
     * Map the `?archived=` query parameter onto a scope.
     *
     * Absent or empty → Active. Unknown values fail CLOSED to Active
     * rather than widening the listing (R43/SEC-FAILCLOSED-001): a typo
     * must never silently surface archived threads.
     */
    public static function fromRequest(?string $archived): self
    {
        return match ($archived) {
            '1', 'true', 'archived' => self::Archived,
            'all' => self::All,
            default => self::Active,
        };
    }
}

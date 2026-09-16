<?php

declare(strict_types=1);

namespace App\Exceptions\Chat;

use RuntimeException;

/**
 * A folder name collided with one the user already owns in this tenant.
 *
 * Raised by {@see \App\Services\Chat\ChatFolderService} when the DB
 * unique fires — which happens when two creates of the same name race
 * past validation, since validate-then-insert is not atomic.
 *
 * A DOMAIN exception rather than a `ValidationException`, mirroring
 * {@see ChatFolderNotOwnedException}: the service is a public PHP surface
 * under R44, so a CLI or queue caller should not have to catch an
 * HTTP-validator type for a database-constraint collision. The HTTP layer
 * translates it into the 422 field error its request contract promises.
 */
final class ChatFolderNameTakenException extends RuntimeException
{
    /** The single source of the user-facing message. */
    public const MESSAGE = 'You already have a folder with this name.';

    public static function forName(string $name): self
    {
        return new self(sprintf('Chat folder name "%s" is already taken for this user and tenant.', $name));
    }
}

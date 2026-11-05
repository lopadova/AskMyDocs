<?php

declare(strict_types=1);

namespace App\Exceptions\Chat;

use RuntimeException;

/**
 * A folder was referenced that does not belong to the acting user in the
 * active tenant.
 *
 * The HTTP layer validates folder ownership in the FormRequest and
 * answers 422, so reaching this exception means a NON-HTTP caller (the
 * service is a public PHP surface under R44) passed an unowned id. It
 * fails loudly rather than silently unfiling or cross-filing the thread
 * (R4/R14).
 */
final class ChatFolderNotOwnedException extends RuntimeException
{
    public static function forFolder(int $folderId): self
    {
        return new self(sprintf('Chat folder %d is not owned by the acting user in this tenant.', $folderId));
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Admin\Connectors;

use RuntimeException;

/**
 * Thrown when the live IMAP folder list cannot be fetched for an installation
 * (server unreachable, credentials rejected, transport error). The controller
 * maps it to HTTP 503 so the caller can tell "couldn't reach the mailbox" from
 * an empty-but-successful folder list (R14 — surface failures loudly, never a
 * 200 with an empty body).
 */
final class ImapFolderListingException extends RuntimeException {}

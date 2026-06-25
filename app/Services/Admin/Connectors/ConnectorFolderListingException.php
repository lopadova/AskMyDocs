<?php

declare(strict_types=1);

namespace App\Services\Admin\Connectors;

use RuntimeException;

/**
 * Thrown when the live folder/label list cannot be fetched for an installation
 * (source unreachable, credentials rejected, transport error) — for any connector
 * that implements SupportsFolderDiscovery, not just IMAP. The controller maps it
 * to HTTP 503 so the caller can tell "couldn't reach the source" from an
 * empty-but-successful folder list (R14 — surface failures loudly, never a 200
 * with an empty body).
 */
final class ConnectorFolderListingException extends RuntimeException {}

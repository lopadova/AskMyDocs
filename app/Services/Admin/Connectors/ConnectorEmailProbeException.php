<?php

declare(strict_types=1);

namespace App\Services\Admin\Connectors;

use RuntimeException;

/**
 * Thrown when the connector "test fetch" diagnostic cannot reach the upstream
 * mailbox (source unreachable, credentials rejected, transport error). The
 * controller maps it to HTTP 503 so the operator can tell "couldn't connect" from
 * a reachable-but-empty mailbox (R14 — surface failures loudly, never a 200 with
 * an empty body that reads as success).
 */
final class ConnectorEmailProbeException extends RuntimeException {}

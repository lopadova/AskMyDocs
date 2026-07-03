<?php

declare(strict_types=1);

namespace App\Services\Admin\Connectors;

use RuntimeException;

/**
 * Thrown when a PRE-SAVE credential connection test cannot confirm the mailbox
 * (server unreachable, credentials rejected, required fields missing, transport
 * error). Unlike {@see ConnectorEmailProbeException}, this never maps to a 5xx:
 * the "test connection" button's whole job is to REPORT reachability, so the
 * controller returns 200 `{ ok:false, error }` — an explicit negative result the
 * FE reads (R14 — not a silent success), distinct from a passing `{ ok:true }`.
 */
final class ConnectorConnectionTestException extends RuntimeException {}

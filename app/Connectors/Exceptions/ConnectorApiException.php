<?php

declare(strict_types=1);

namespace App\Connectors\Exceptions;

/**
 * v4.5/W2 (Copilot iter1 finding #4) — Raised when a connector's
 * upstream API call fails for non-auth reasons:
 *
 *   - 4xx other than 401/403 (bad request, rate limit, conflict, etc.)
 *   - 5xx (transient upstream outage)
 *   - non-JSON / malformed response bodies
 *
 * Use {@see ConnectorAuthException} ONLY for 401/403 + OAuth token-
 * exchange / state-token failures. Misclassifying a 503 upstream
 * outage as an auth problem hides the real cause from the admin UI
 * and triggers unnecessary "reinstall the connector" prompts.
 *
 * `ConnectorSyncJob` distinguishes the two: `ConnectorAuthException`
 * marks the installation as `errored` and stops retrying; this one
 * lets the job retry per its backoff policy (transient outages
 * usually resolve within a few minutes).
 */
class ConnectorApiException extends \RuntimeException {}

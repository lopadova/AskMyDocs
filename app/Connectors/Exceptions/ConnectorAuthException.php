<?php

declare(strict_types=1);

namespace App\Connectors\Exceptions;

/**
 * v4.5/W1 — Raised when an OAuth flow / auth boundary fails:
 *
 *   - Invalid or expired CSRF state token
 *   - Upstream rejected the code-exchange (HTTP 4xx during
 *     `/oauth/token` POST)
 *   - Refresh-token rotation failed
 *   - Scope grant came back incomplete
 *   - HTTP 401 / 403 on any subsequent API call (credentials are
 *     present but the provider rejects them — typically means the
 *     operator revoked the integration upstream)
 *
 * Surfaces 4xx semantics: the framework returns HTTP 400 + an
 * actionable message to the admin UI so the user can re-initiate
 * the install flow. `ConnectorSyncJob` marks the installation as
 * `errored` and stops retrying on this exception type — there is
 * no point retrying when the credentials themselves are invalid.
 *
 * For non-auth API failures (5xx, 429, malformed body, network
 * timeout, etc.), use {@see ConnectorApiException} instead so the
 * job runner can apply its retry/backoff policy. Misclassifying a
 * transient outage as an auth failure hides the real cause and
 * triggers unnecessary "reinstall the connector" prompts.
 */
class ConnectorAuthException extends \RuntimeException {}

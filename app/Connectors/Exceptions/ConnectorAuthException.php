<?php

declare(strict_types=1);

namespace App\Connectors\Exceptions;

/**
 * v4.5/W1 — Raised when an OAuth flow fails: invalid state token,
 * upstream rejected the code-exchange, refresh-token rotation failed,
 * or scope grant came back incomplete.
 *
 * Surfaces 4xx semantics: the framework returns HTTP 400 + an
 * actionable message to the admin UI so the user can re-initiate
 * the install flow.
 */
class ConnectorAuthException extends \RuntimeException {}

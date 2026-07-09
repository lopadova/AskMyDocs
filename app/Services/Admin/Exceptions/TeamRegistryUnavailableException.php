<?php

namespace App\Services\Admin\Exceptions;

use RuntimeException;

/**
 * Thrown by {@see \App\Services\Admin\TeamRegistryService} when a
 * create/rename is attempted but the `tenants` registry table is
 * absent (the `padosoft/laravel-ai-act-compliance` package was never
 * migrated). A team's editable display name has nowhere to persist
 * without that table, so the capability degrades to unavailable.
 *
 * The controller maps this to HTTP 503 (R14/R43 — surface the failure
 * loudly with the correct status; never a 500 or a silent 200). Distinct
 * class (instead of a generic RuntimeException + message sniffing) so the
 * status mapping stays stable when the copy drifts.
 */
class TeamRegistryUnavailableException extends RuntimeException
{
}

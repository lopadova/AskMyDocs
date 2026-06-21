<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * v8.19/W3 — master-switch gate for the laravel-ai-guardrails-admin SPA.
 *
 * The package mounts its catch-all SPA route UNCONDITIONALLY on boot (it has no
 * `enabled` flag of its own), so this middleware — placed FIRST in
 * `config('ai-guardrails-admin.middleware')` — returns a clean 404 for every
 * route under the prefix while `ai-guardrails-admin.enabled` is false. That
 * keeps the cockpit dark on a fresh deploy (R43 OFF-state) and is the correct
 * semantic for a disabled subsystem (R14 — a 404, never a 500 or a blank page).
 * Mirrors App\Http\Middleware\FlowAdminEnabled.
 */
final class GuardrailsAdminEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if ((bool) config('ai-guardrails-admin.enabled', false) !== true) {
            abort(404);
        }

        return $next($request);
    }
}

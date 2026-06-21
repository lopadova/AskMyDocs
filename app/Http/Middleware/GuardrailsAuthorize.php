<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Method-aware authorization for the laravel-ai-guardrails API (R32).
 *
 * The guardrails package controllers do NO internal authorization — every
 * route (the overview/audit/firewall/output-stats/approvals READS and the
 * `PUT /settings` + `POST /approvals/*` MUTATIONS) is gated solely by the
 * route middleware. A single view gate would let `admin` mutate the guardrail
 * ruleset / approve parked destructive tool calls, which is wrong.
 *
 * So we split by HTTP method, using `Request::isMethodSafe()` as the predicate:
 *   - safe methods (GET/HEAD/OPTIONS/TRACE) → `viewAiGuardrails`
 *     (super-admin + admin)
 *   - mutating methods (POST/PUT/PATCH/DELETE) → `manageAiGuardrails`
 *     (super-admin)
 *
 * Mounted as `guardrails.authorize` in `config('ai-guardrails.api.middleware')`,
 * after `auth:sanctum` + `tenant.authorize` (a guest is already a 401 by then;
 * this layer only decides 403-vs-pass for an authenticated user).
 *
 * NB: the package's `/try/screen` + `/try/sanitize` sandbox endpoints are POSTs
 * that mutate nothing, but treating every non-GET as "manage" errs toward the
 * restrictive side — relaxing those specific safe POSTs to `admin` is a
 * documented future refinement.
 */
final class GuardrailsAuthorize
{
    public function handle(Request $request, Closure $next): Response
    {
        $ability = $request->isMethodSafe() ? 'viewAiGuardrails' : 'manageAiGuardrails';

        if (Gate::denies($ability)) {
            abort(403);
        }

        return $next($request);
    }
}

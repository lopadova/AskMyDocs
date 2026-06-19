<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Method-aware authorization for the laravel-ai-finops API (R32).
 *
 * The finops package controllers do NO internal authorization — every route
 * (reads AND mutations: budgets, policies, kill-switches, cost-centers,
 * credits, approvals) is gated solely by the route middleware. A single view
 * gate would let `admin` mutate financial-governance state, which is wrong.
 *
 * So we split by HTTP method, using `Request::isMethodSafe()` as the predicate:
 *   - safe methods (GET/HEAD/OPTIONS/TRACE, per RFC 7231 / Symfony semantics)
 *     → `viewAiFinOps`   (super-admin + admin)
 *   - mutating methods (POST/PUT/PATCH/DELETE) → `manageAiFinOps` (super-admin)
 *
 * Mounted as `finops.authorize` inside `config('ai-finops.routes.auth_middleware')`,
 * so it runs AFTER `auth:sanctum` + `tenant.authorize` (a guest is already a 401
 * by then; this layer only decides 403-vs-pass for an authenticated user).
 *
 * NB: this treats every non-GET as "manage", so analysis POSTs (whatif/simulate,
 * policies/validate, copilot/query, diagnostics/estimate) also require super-admin.
 * That errs intentionally toward the restrictive side for cost data; relaxing
 * specific safe POSTs to `admin` is a documented future refinement.
 */
final class FinOpsAuthorize
{
    public function handle(Request $request, Closure $next): Response
    {
        $ability = $request->isMethodSafe() ? 'viewAiFinOps' : 'manageAiFinOps';

        if (Gate::denies($ability)) {
            abort(403);
        }

        return $next($request);
    }
}

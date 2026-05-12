<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin\Concerns;

use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * v4.7/W3 — Shared viewer-mutation guard for the Tabular Review surface.
 *
 * Extracted on Copilot iter 5 (PR #164) so `TabularReviewController` and
 * `TabularReviewStreamController` enforce the same role rule + the same
 * 403 message. Drift between the two controllers would mean a viewer
 * gets a different error depending on whether they hit the synchronous
 * `/generate` or the SSE `/generate-stream` endpoint.
 *
 * R30/R31 compatible: the gate composes with the tenant scope on each
 * controller; this trait only handles the role check.
 *
 * Usage:
 *
 *     use App\Http\Controllers\Api\Admin\Concerns\DeniesViewerMutations;
 *
 *     final class FooController extends Controller {
 *         use DeniesViewerMutations;
 *
 *         public function store(Request $r) {
 *             $this->denyMutationForViewer($r);
 *             // ... mutation logic ...
 *         }
 *     }
 */
trait DeniesViewerMutations
{
    /**
     * Reject write actions when the caller has only `viewer` role.
     * super-admin / admin are admitted upstream by the Gate.
     */
    private function denyMutationForViewer(Request $request): void
    {
        $user = $request->user();
        if ($user === null) {
            return;
        }
        if (method_exists($user, 'hasRole') && $user->hasRole('viewer')
            && ! $user->hasAnyRole(['admin', 'super-admin'])) {
            throw new AccessDeniedHttpException('Viewers cannot mutate tabular reviews.');
        }
    }
}

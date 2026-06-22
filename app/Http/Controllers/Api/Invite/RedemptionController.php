<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Invite;

use App\Http\Requests\Invite\RedeemRequest;
use App\Http\Resources\Invite\RedemptionResource;
use App\Services\Invite\CodeValidator;
use App\Services\Invite\InvitationService;
use App\Services\Invite\RedemptionService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * User-facing redemption surface (R44 — HTTP API layer over the same
 * RedemptionService the PHP and MCP surfaces use).
 *
 * - POST /api/invite/redeem    authenticated account claims a code.
 * - POST /api/invite/validate  advisory pre-check (writes nothing).
 *
 * Error → status mapping is driven by RedemptionError::httpStatus() (R14):
 * failures are surfaced with the correct semantic, never 200-with-empty.
 * `already_redeemed` is idempotent SUCCESS (200), not a failure.
 */
final class RedemptionController extends Controller
{
    public function __construct(
        private readonly RedemptionService $redemption,
        private readonly CodeValidator $validator,
        private readonly TenantContext $tenant,
        private readonly InvitationService $invitations,
    ) {
    }

    public function redeem(RedeemRequest $request): JsonResponse
    {
        $result = $this->redemption->redeem(
            $request->string('code')->toString(),
            $request->user(),
            [
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ],
        );

        if (! $result->ok) {
            return response()->json([
                'ok' => false,
                'error' => $result->error->value,
            ], $result->error->httpStatus());
        }

        return response()->json([
            'ok' => true,
            'already' => $result->already,
            'redemption' => new RedemptionResource($result->redemption),
        ], 200);
    }

    public function validateCode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:128'],
        ]);

        $result = $this->validator->validate($validated['code'], $this->tenant->current());

        if (! $result->ok) {
            return response()->json([
                'valid' => false,
                'error' => $result->error->value,
            ], $result->error->httpStatus());
        }

        return response()->json([
            'valid' => true,
            'code_kind' => $result->code->code_kind,
        ], 200);
    }

    /**
     * In-app pending-invitations badge for the authenticated account. Counts
     * pending invitations addressed to the user's own email.
     */
    public function pendingCount(Request $request): JsonResponse
    {
        return response()->json([
            'pending' => $this->invitations->pendingCountFor((string) $request->user()->email),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Invitations;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

/**
 * The invite was consumed, but its required account access is not complete.
 *
 * The redemption is intentionally retained as the idempotency/recovery anchor.
 * A later login retries completion instead of sending the user into company
 * onboarding with a tenant invitation that has already been consumed.
 */
final class RegistrationCompletionPendingException extends RuntimeException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct(
            'Registration provisioning is incomplete and will be retried on the next sign-in.',
            0,
            $previous,
        );
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'error' => 'registration_provisioning_pending',
            'message' => 'Your company access is still being finalized. Please sign in again.',
        ], 503);
    }
}

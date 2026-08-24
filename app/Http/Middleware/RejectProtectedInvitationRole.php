<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\PlatformAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Defence at the host boundary for invitation package versions that do not
 * yet know about AskMyDocs's protected platform role.
 */
final class RejectProtectedInvitationRole
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->containsSystemRole($request->all())) {
            return response()->json([
                'message' => 'The system-admin role cannot be granted through invitations.',
                'errors' => [
                    'grant.role' => ['Use the dedicated operator command for system administrator access.'],
                ],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $next($request);
    }

    /**
     * @param array<mixed> $payload
     */
    private function containsSystemRole(array $payload): bool
    {
        foreach ($payload as $key => $value) {
            if ($key === 'role' && $value === PlatformAccess::SYSTEM_ADMIN_ROLE) {
                return true;
            }

            if (is_array($value) && $this->containsSystemRole($value)) {
                return true;
            }
        }

        return false;
    }
}

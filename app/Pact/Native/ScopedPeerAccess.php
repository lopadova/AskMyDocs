<?php

declare(strict_types=1);

namespace App\Pact\Native;

use Closure;
use Illuminate\Http\Request;
use PactLaravel\Models\Connection;
use PactLaravel\OAuth\PeerMiddleware;
use Symfony\Component\HttpFoundation\Response;

final readonly class ScopedPeerAccess
{
    public function __construct(private PeerMiddleware $peer) {}

    public function handle(Request $request, Closure $next, string $audience): Response
    {
        return $this->peer->handle($request, static function (Request $request) use ($next): Response {
            if (config('pact.role') === 'client') {
                $connection = $request->attributes->get('pact.connection');
                abort_unless($connection instanceof Connection && app(SubjectResolver::class)->find((string) $connection->user_id) !== null, 403);
            }

            return $next($request);
        }, $audience);
    }
}

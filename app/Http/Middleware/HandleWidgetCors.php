<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * HandleWidgetCors — CORS dedicato al canale pubblico `/api/widget/*`.
 *
 * Il CORS statico (`config/cors.php`) elenca origini esplicite e non può
 * coprire i domini cliente arbitrari su cui gira il widget. Qui RIFLETTIAMO
 * l'`Origin` della richiesta perché il vero gate di sicurezza è
 * `ResolveWidgetKey` (key + allowlist + rate-limit) sulla richiesta reale;
 * inoltre il canale widget NON usa cookie (`supports_credentials:false`,
 * niente `Access-Control-Allow-Credentials`), quindi riflettere l'Origin non
 * espone sessioni. Risponde direttamente al preflight (OPTIONS) PRIMA che
 * scattino throttle/auth, così il browser può procedere; l'allowlist viene
 * poi applicata sulla richiesta effettiva.
 *
 * È prepended a livello globale (come ResolveTenant) ma agisce SOLO su
 * `api/widget/*`: così gestisce il preflight (OPTIONS) PRIMA del routing —
 * evitando il 405 su una rotta GET/POST-only — e imposta gli header anche
 * sulle risposte d'errore (401/403/429) così il browser può leggerle (R14).
 */
final class HandleWidgetCors
{
    private const ALLOW_HEADERS = 'Content-Type, Authorization, X-Widget-Key, X-Widget-Step-Id, X-Requested-With';
    private const ALLOW_METHODS = 'POST, GET, OPTIONS';

    public function handle(Request $request, Closure $next): Response
    {
        // Agisce solo sul canale widget; ogni altra rotta passa invariata.
        if (! $request->is('api/widget/*')) {
            return $next($request);
        }

        $origin = (string) $request->header('Origin', '');

        if ($request->getMethod() === 'OPTIONS') {
            $response = response('', 204);
            $this->applyCors($response, $origin);

            return $response;
        }

        $response = $next($request);
        $this->applyCors($response, $origin);

        return $response;
    }

    private function applyCors(Response $response, string $origin): void
    {
        // Riflette l'Origin (o `*` se assente, es. chiamate non-browser).
        $response->headers->set('Access-Control-Allow-Origin', $origin !== '' ? $origin : '*');
        $response->headers->set('Access-Control-Allow-Methods', self::ALLOW_METHODS);
        $response->headers->set('Access-Control-Allow-Headers', self::ALLOW_HEADERS);
        $response->headers->set('Access-Control-Max-Age', '600');
        // L'output dipende dall'Origin → niente cache cross-origin sbagliata.
        $response->headers->set('Vary', 'Origin');
    }
}

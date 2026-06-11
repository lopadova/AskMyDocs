<?php

declare(strict_types=1);

namespace Tests\Unit\Widget;

use App\Http\Middleware\HandleWidgetCors;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Tests\TestCase;

/**
 * #24 — HandleWidgetCors è l'unica autorità CORS del canale `/api/widget/*`.
 *
 * In produzione è prepended (index 0, outermost — verificato) mentre il CORS
 * GLOBALE (Illuminate\Http\Middleware\HandleCors) è inner: HandleWidgetCors
 * elabora la response per ULTIMO, quindi può rimuovere l'eventuale
 * Access-Control-Allow-Credentials che il motore globale aggiunge per le
 * origini in CORS_ALLOWED_ORIGINS. Qui testiamo la logica del middleware in
 * isolamento (deterministico, indipendente dall'ordinamento del framework).
 */
final class WidgetCorsTest extends TestCase
{
    public function test_strips_allow_credentials_added_by_the_global_cors(): void
    {
        $mw = new HandleWidgetCors;
        $request = Request::create('/api/widget/setup', 'GET');
        $request->headers->set('Origin', 'https://x.test');

        $response = $mw->handle($request, function (): Response {
            // Simula il CORS globale che ha già messo ACAC:true (origine elencata).
            $r = new Response('{}', 200, ['Content-Type' => 'application/json']);
            $r->headers->set('Access-Control-Allow-Credentials', 'true');

            return $r;
        });

        $this->assertFalse(
            $response->headers->has('Access-Control-Allow-Credentials'),
            'HandleWidgetCors deve rimuovere Access-Control-Allow-Credentials.',
        );
        $this->assertSame('https://x.test', $response->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_preflight_options_is_204_and_uncredentialed(): void
    {
        $mw = new HandleWidgetCors;
        $request = Request::create('/api/widget/sessions/start', 'OPTIONS');
        $request->headers->set('Origin', 'https://x.test');

        // Il preflight è gestito PRIMA del routing: il closure non deve girare.
        $response = $mw->handle($request, fn (): Response => new Response('should-not-run', 500));

        $this->assertSame(204, $response->getStatusCode());
        $this->assertFalse($response->headers->has('Access-Control-Allow-Credentials'));
        $this->assertSame('https://x.test', $response->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_non_widget_path_is_left_untouched(): void
    {
        $mw = new HandleWidgetCors;
        $request = Request::create('/api/admin/foo', 'GET');

        $response = $mw->handle($request, fn (): Response => new Response('ok', 200));

        $this->assertFalse($response->headers->has('Access-Control-Allow-Origin'));
    }
}

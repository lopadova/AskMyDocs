<?php

declare(strict_types=1);

namespace Tests\Feature\Routes;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * v8.0.2 / deep-review B — the REAL SPA chat path goes through
 * `POST /conversations/{conversation}/messages` (synchronous) and
 * `POST /conversations/{conversation}/messages/stream` (SSE).
 *
 * The AI Act middleware stack (`ai.disclosure` + optional
 * `ai.consent:<feature>`) was previously mounted ONLY on
 * `POST /api/kb/chat`, which is the stateless JSON API surface,
 * not the path the SPA actually uses. That left Art. 50 disclosure
 * AND the consent gate bypassable for every chat turn in the
 * user-facing UX — a regulatory exposure.
 *
 * These tests inspect the registered routes (NOT a unit-style
 * inline Route::post() stub like KbChatAiActMiddlewareTest) so
 * they fail the moment a future refactor drops the middleware
 * from either endpoint.
 *
 * Structural focus: the runtime AI Act behaviour itself
 * (disclosure header content, consent denial codes) is exercised
 * by KbChatAiActMiddlewareTest against the same alias map. Here
 * we lock the WIRE-UP — which routes get the gates.
 */
final class ConversationChatAiActMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_conversation_messages_post_carries_ai_disclosure_middleware(): void
    {
        $middleware = $this->resolveMiddlewareFor('POST', 'conversations/{conversation}/messages');

        $this->assertContains(
            'ai.disclosure',
            $middleware,
            'B (deep-review v8.0.2): POST /conversations/{id}/messages must carry '
            . 'the ai.disclosure middleware — same gate as /api/kb/chat.',
        );
    }

    public function test_conversation_messages_stream_post_carries_ai_disclosure_middleware(): void
    {
        $middleware = $this->resolveMiddlewareFor(
            'POST',
            'conversations/{conversation}/messages/stream',
        );

        $this->assertContains(
            'ai.disclosure',
            $middleware,
            'B (deep-review v8.0.2): POST /conversations/{id}/messages/stream must '
            . 'carry the ai.disclosure middleware — the SSE chat variant is the '
            . 'primary UX path for the SPA.',
        );
    }

    public function test_conversation_messages_post_mounts_consent_gate_when_host_opts_in(): void
    {
        // Structural assertion against routes/web.php: re-loading the
        // route file mid-test is brittle under Testbench (base_path()
        // points at Testbench's dummy laravel dir), so we assert the
        // CONDITIONAL itself is wired correctly via a source grep.
        // The runtime behaviour (consent denial 403 / allow on grant)
        // is exercised by KbChatAiActMiddlewareTest against the same
        // alias map; here we only verify the SPA endpoints share the
        // wiring.
        $source = file_get_contents($this->routesWebPath());

        $this->assertNotFalse($source, 'routes/web.php must be readable.');
        $this->assertMatchesRegularExpression(
            '/ai-act-compliance\.consent\.gate_chat_feature/',
            $source,
            'B (deep-review v8.0.2): routes/web.php must read the host opt-in '
            . 'config key (same conditional as routes/api.php) so the SPA chat '
            . 'endpoints mount the consent gate when the host opts in.',
        );
        $this->assertMatchesRegularExpression(
            '/[\$]chatPostMiddleware\[\][[:space:]]*=[[:space:]]*[\'"]ai\.consent:/',
            $source,
            'B (deep-review v8.0.2): the consent middleware alias must be '
            . 'appended to the chat-post stack when the config gate is set, '
            . 'mirroring routes/api.php.',
        );
    }

    public function test_conversation_messages_stream_mounts_consent_gate_when_host_opts_in(): void
    {
        $source = file_get_contents($this->routesWebPath());

        $this->assertNotFalse($source, 'routes/web.php must be readable.');
        $this->assertMatchesRegularExpression(
            '/[\$]chatSseMiddleware\[\][[:space:]]*=[[:space:]]*[\'"]ai\.consent:/',
            $source,
            'B (deep-review v8.0.2): the SSE stream variant must also append '
            . 'the consent alias to its middleware stack when the config gate '
            . 'is set — otherwise the streaming UX path bypasses the gate the '
            . 'synchronous path now enforces.',
        );
    }

    public function test_conversation_messages_post_keeps_redact_chat_pii_first(): void
    {
        $middleware = $this->resolveMiddlewareFor('POST', 'conversations/{conversation}/messages');

        // R-deep-review B preserves the existing redact-chat-pii layer
        // — disclosure/consent operate at the auth/response layer
        // and the redaction must still pre-process the inbound body
        // BEFORE the controller reads it.
        $this->assertContains(
            'redact-chat-pii',
            $middleware,
            'B (deep-review v8.0.2): redact-chat-pii must remain on the messages '
            . 'POST so the AI Act gates do not unwind PII protection.',
        );
    }

    /**
     * @return array<int, string>
     */
    private function resolveMiddlewareFor(string $method, string $uri): array
    {
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! in_array(strtoupper($method), $route->methods(), true)) {
                continue;
            }
            if ($route->uri() !== $uri) {
                continue;
            }

            return $route->gatherMiddleware();
        }

        $this->fail("Route {$method} /{$uri} not registered.");
    }

    private function routesWebPath(): string
    {
        // Avoid base_path() — under Testbench it resolves to the
        // dummy laravel directory, not the host project. The test
        // file's known relative location is the stable anchor.
        return dirname(__DIR__, 3) . '/routes/web.php';
    }
}

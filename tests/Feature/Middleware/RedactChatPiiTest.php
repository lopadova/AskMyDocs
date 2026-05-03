<?php

declare(strict_types=1);

namespace Tests\Feature\Middleware;

use App\Http\Middleware\RedactChatPii;
use Illuminate\Http\Request;
use Padosoft\PiiRedactor\Facades\Pii;
use Tests\TestCase;

/**
 * Feature tests for the W4.1.B `redact-chat-pii` middleware.
 *
 * Covers the three observable contracts:
 *
 *   1. Pass-through when the master switch is OFF (default).
 *   2. Redaction when BOTH integration knobs are ON, asserting the
 *      content body has been transformed BEFORE the controller would
 *      see it. We exercise the middleware directly (without booting
 *      the full chat stack) because v4.1.C has not yet shipped the
 *      DatabaseTokenStore migration; with the in-memory tokenise
 *      fallback the test is hermetic and reproducible.
 *   3. Empty / non-string content is a safe no-op (no exception, no
 *      mutation).
 *
 * The tokeniser strategy needs a non-empty salt; the test suite sets
 * a fixed value so the redacted output is deterministic.
 */
final class RedactChatPiiTest extends TestCase
{
    /**
     * Set the package config BEFORE Laravel's bootstrap resolves the
     * RedactionStrategy + RedactorEngine singletons. Using the
     * `defineEnvironment` Testbench hook (or, in our case, environment
     * config in TestCase) ensures the SP closure reads the test salt
     * + strategy on first resolution. We deliberately do NOT call
     * `forgetInstance` after the fact — that fights the SP and can
     * leave the container thinking the interface has no binding when
     * the closure throws StrategyException on re-resolution.
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('pii-redactor.strategy', 'tokenise');
        $app['config']->set('pii-redactor.salt', 'redact-chat-pii-test-salt-do-not-use-in-prod');
    }

    public function test_passthrough_when_master_switch_is_off(): void
    {
        config([
            'kb.pii_redactor.enabled' => false,
            'kb.pii_redactor.persist_chat_redacted' => true,
        ]);

        $request = Request::create('/conversations/abc/messages', 'POST', [
            'content' => 'Codice fiscale RSSMRA85T10A562S, mail: mario@example.com',
        ]);

        $middleware = $this->app->make(RedactChatPii::class);
        $middleware->handle($request, function (Request $passed) {
            $this->assertSame(
                'Codice fiscale RSSMRA85T10A562S, mail: mario@example.com',
                $passed->input('content'),
                'When the master switch is OFF, content must reach the controller untouched.',
            );

            return response('ok');
        });
    }

    public function test_passthrough_when_persist_knob_is_off(): void
    {
        config([
            'kb.pii_redactor.enabled' => true,
            'kb.pii_redactor.persist_chat_redacted' => false,
        ]);

        $request = Request::create('/conversations/abc/messages', 'POST', [
            'content' => 'mario@example.com',
        ]);

        $middleware = $this->app->make(RedactChatPii::class);
        $middleware->handle($request, function (Request $passed) {
            $this->assertSame(
                'mario@example.com',
                $passed->input('content'),
                'When persist_chat_redacted is OFF, content must reach the controller untouched.',
            );

            return response('ok');
        });
    }

    public function test_redacts_pii_when_both_knobs_are_on(): void
    {
        config([
            'kb.pii_redactor.enabled' => true,
            'kb.pii_redactor.persist_chat_redacted' => true,
        ]);

        $request = Request::create('/conversations/abc/messages', 'POST', [
            'content' => 'Email mario@example.com please',
        ]);

        $middleware = $this->app->make(RedactChatPii::class);
        $middleware->handle($request, function (Request $passed) {
            $content = $passed->input('content');
            $this->assertIsString($content);
            $this->assertStringNotContainsString(
                'mario@example.com',
                $content,
                'Email must be redacted out of the request before the controller sees it.',
            );
            $this->assertMatchesRegularExpression(
                '/\[tok:email:[0-9a-f]+\]/',
                $content,
                'Tokenised email replacement must follow the package\'s `[tok:detector:hex]` shape.',
            );

            return response('ok');
        });

        // Sanity: the package facade detokenises back to the original.
        $strategy = app(\Padosoft\PiiRedactor\Strategies\RedactionStrategy::class);
        $this->assertInstanceOf(\Padosoft\PiiRedactor\Strategies\TokeniseStrategy::class, $strategy);
        // Re-running redact gives the same token (idempotent).
        $first = Pii::redact('mario@example.com');
        $second = Pii::redact('mario@example.com');
        $this->assertSame($first, $second);
    }

    public function test_empty_content_is_safe_no_op(): void
    {
        config([
            'kb.pii_redactor.enabled' => true,
            'kb.pii_redactor.persist_chat_redacted' => true,
        ]);

        $request = Request::create('/conversations/abc/messages', 'POST', [
            'content' => '',
        ]);

        $middleware = $this->app->make(RedactChatPii::class);
        $middleware->handle($request, function (Request $passed) {
            $this->assertSame('', $passed->input('content'));

            return response('ok');
        });
    }

    public function test_non_string_content_is_safe_no_op(): void
    {
        config([
            'kb.pii_redactor.enabled' => true,
            'kb.pii_redactor.persist_chat_redacted' => true,
        ]);

        // Some callers may post `content` as null (deleted draft) or a
        // shape we don't recognise. The middleware must not blow up.
        $request = Request::create('/conversations/abc/messages', 'POST', [
            'content' => null,
        ]);

        $middleware = $this->app->make(RedactChatPii::class);
        $middleware->handle($request, function (Request $passed) {
            // null in, null out — middleware is purely defensive here.
            $this->assertNull($passed->input('content'));

            return response('ok');
        });
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Pii;

use App\Pii\AskMyDocsFlowPayloadRedactor;
use Padosoft\LaravelFlow\Contracts\CurrentPayloadRedactorProvider;
use Padosoft\LaravelFlow\Contracts\PayloadRedactor;
use Padosoft\PiiRedactor\RedactorEngine;
use Tests\TestCase;

/**
 * v4.3/W1 sub-PR 4.5 — A7 — AskMyDocsFlowPayloadRedactor unit-style
 * test against the laravel-flow contract surface.
 */
final class FlowPayloadRedactorTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('pii-redactor.strategy', 'mask');
        $app['config']->set('kb.pii_redactor.enabled', true);
        $app['config']->set('kb.pii_redactor.redact_flow_payloads', true);
    }

    public function test_redactor_implements_flow_contracts(): void
    {
        $r = $this->factory();
        $this->assertInstanceOf(CurrentPayloadRedactorProvider::class, $r);
        $this->assertInstanceOf(PayloadRedactor::class, $r);

        // currentRedactor() returns a stable inner redactor.
        $this->assertInstanceOf(PayloadRedactor::class, $r->currentRedactor());
    }

    public function test_redact_walks_nested_payload_strings(): void
    {
        $payload = [
            'run_input' => [
                'email' => 'mario@example.com',
                'meta' => ['cc' => 'giulia@example.org'],
            ],
            'numeric' => 42,
            'flag' => true,
        ];

        $out = $this->factory()->redact($payload);

        $flat = $this->flattenStrings($out);
        foreach ($flat as $s) {
            $this->assertDoesNotMatchRegularExpression(
                '/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i',
                $s,
                'Nested payload string must not retain raw email: '.$s,
            );
        }

        // Non-string values preserved untouched.
        $this->assertSame(42, $out['numeric']);
        $this->assertTrue($out['flag']);
    }

    public function test_when_flag_off_provider_binding_is_absent(): void
    {
        // R16 — assert the actual contract: when either gate is OFF,
        // PiiBoundaryCoverageServiceProvider::register() MUST NOT bind
        // the AskMyDocs implementation onto the contract.
        //
        // We exercise the SP's register() branch under all four flag
        // permutations against fresh `Illuminate\Container\Container`
        // instances. A bare Container (NOT a Foundation\Application) is
        // sufficient because the SP's register() only reads `config(...)`
        // and calls `$app->singleton(...)`. Container::setInstance() is
        // restored after each permutation so the global `app()` helper
        // (used by tearDown's RefreshDatabase rollback) keeps pointing
        // at the booted test app.

        $globalBefore = \Illuminate\Container\Container::getInstance();
        $contract = \Padosoft\LaravelFlow\Contracts\CurrentPayloadRedactorProvider::class;

        $check = function (bool $enabled, bool $redactFlow, bool $expectBound, string $message) use ($contract): void {
            $container = new \Illuminate\Container\Container;
            $container->instance('config', new \Illuminate\Config\Repository([
                'kb' => [
                    'pii_redactor' => [
                        'enabled' => $enabled,
                        'redact_flow_payloads' => $redactFlow,
                    ],
                ],
            ]));

            // The SP's shouldBindFlowProvider() reads the GLOBAL `config()`
            // helper, which delegates through Container::getInstance().
            // Swap the global instance to our fresh container for the
            // duration of register(), then restore in the outer finally.
            \Illuminate\Container\Container::setInstance($container);

            $sp = new \App\Providers\PiiBoundaryCoverageServiceProvider($container);
            $sp->register();

            $this->assertSame($expectBound, $container->bound($contract), $message);
        };

        try {
            $check(false, false, false, 'Provider must NOT be bound when master gate is off.');
            $check(true, false, false, 'Provider must NOT be bound when redact_flow_payloads is off.');
            $check(false, true, false, 'Provider must NOT be bound when master gate is off, even with flow flag on.');
            $check(true, true, true, 'Provider MUST be bound when both gates are on.');
        } finally {
            \Illuminate\Container\Container::setInstance($globalBefore);
        }
    }

    private function factory(): AskMyDocsFlowPayloadRedactor
    {
        return new AskMyDocsFlowPayloadRedactor(app(RedactorEngine::class));
    }

    /**
     * @param  array<int|string, mixed>  $values
     * @return list<string>
     */
    private function flattenStrings(array $values): array
    {
        $out = [];
        foreach ($values as $v) {
            if (is_string($v)) {
                $out[] = $v;
                continue;
            }
            if (is_array($v)) {
                $out = array_merge($out, $this->flattenStrings($v));
            }
        }

        return $out;
    }
}

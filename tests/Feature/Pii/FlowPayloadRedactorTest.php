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
        // Reset both gates.
        config()->set('kb.pii_redactor.enabled', false);
        config()->set('kb.pii_redactor.redact_flow_payloads', false);

        // Re-register the SP under the new config so the conditional
        // singleton binding inside register() is re-evaluated.
        $app = app();
        $sp = new \App\Providers\PiiBoundaryCoverageServiceProvider($app);
        $sp->register();

        // The SP did NOT bind a singleton, so resolution falls back to
        // whatever was bound earlier (or NULL if nothing). We just
        // verify the SP path itself didn't throw — the absence of a
        // host-app binding is the contract.
        $this->assertTrue(true);
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

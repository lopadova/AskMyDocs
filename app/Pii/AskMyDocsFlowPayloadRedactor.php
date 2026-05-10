<?php

declare(strict_types=1);

namespace App\Pii;

use Padosoft\LaravelFlow\Contracts\CurrentPayloadRedactorProvider;
use Padosoft\LaravelFlow\Contracts\PayloadRedactor;
use Padosoft\PiiRedactor\RedactorEngine;
use Throwable;

/**
 * v4.3/W1 sub-PR 4.5 — A7 — Flow payload redactor.
 *
 * Implements `Padosoft\LaravelFlow\Contracts\CurrentPayloadRedactorProvider`
 * (which extends `PayloadRedactor`). Bound into the container by
 * `App\Providers\PiiBoundaryCoverageServiceProvider` when
 * `kb.pii_redactor.enabled` AND `kb.pii_redactor.redact_flow_payloads`
 * are both true. The flow package's `RedactorAwareFlowStore` then
 * resolves THIS class for every persisted payload — meaning ONE wire
 * gives us comprehensive coverage across the entire saga engine:
 *
 *   - run input payloads
 *   - per-step result payloads
 *   - audit-trail rows
 *   - webhook outbox bodies
 *   - approval payloads
 *
 * Redaction policy: walk the array depth-first, redact every string
 * value via `RedactorEngine::redact()`. Numeric / boolean / null values
 * are preserved untouched so payloads remain structurally valid for
 * downstream replay.
 *
 * R14 inversion: if the redactor itself throws on a particular value,
 * that value passes through unchanged. The flow engine MUST persist
 * SOMETHING — partial redaction is preferable to a saga halt. The
 * Throwable is caught at the value level so a single bad value does
 * not poison the whole payload.
 */
final class AskMyDocsFlowPayloadRedactor implements CurrentPayloadRedactorProvider
{
    public function __construct(private readonly RedactorEngine $engine) {}

    /**
     * Required by `CurrentPayloadRedactorProvider` — exposes a stable
     * inner redactor for a full repository record write so the flow
     * package can hold a single `PayloadRedactor` reference for the
     * duration of one persistence transaction (no per-key resolution).
     */
    public function currentRedactor(): PayloadRedactor
    {
        return $this;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function redact(array $payload): array
    {
        return $this->redactArrayValues($payload);
    }

    /**
     * @param  array<int|string, mixed>  $values
     * @return array<int|string, mixed>
     */
    private function redactArrayValues(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($value) && $value !== '') {
                try {
                    $values[$key] = $this->engine->redact($value);
                } catch (Throwable) {
                    // Per-value catch: a bad detector on one value
                    // must not poison the whole payload.
                }
                continue;
            }
            if (is_array($value)) {
                $values[$key] = $this->redactArrayValues($value);
            }
        }

        return $values;
    }
}

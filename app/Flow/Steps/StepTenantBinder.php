<?php

declare(strict_types=1);

namespace App\Flow\Steps;

use App\Support\TenantContext;
use Padosoft\LaravelFlow\Exceptions\FlowInputException;
use Padosoft\LaravelFlow\FlowContext;

/**
 * Shared bootstrap for every kb.* Flow step.
 *
 * R30/R31 — every tenant-aware Eloquent query must run under the right
 * TenantContext. The Flow engine is tenant-agnostic, so we carry the
 * tenant_id through {@see FlowContext::$input} (key `tenant_id`).
 * Callers MUST set `input['tenant_id']` when they dispatch a flow that
 * touches tenant-aware tables.
 *
 * IngestDocumentJob does this in `handle()` before it calls
 * `Flow::execute('kb.ingest', $input, $options)`.
 *
 * Per Copilot PR #115 review iteration 1: this method now FAILS LOUD
 * when `tenant_id` is missing, empty, or invalid. The previous silent
 * no-op behaviour violated R30/R31 ("never silently fall back to the
 * default tenant") — a missing tenant_id meant the step would run
 * under the worker's default-tenant context, with downstream queries
 * silently mis-attributing data. {@see TenantContext::set()} validates
 * the format internally and throws InvalidArgumentException on bad
 * input; we wrap that as a FlowInputException so the engine treats
 * it as a step failure → compensation fires.
 */
final class StepTenantBinder
{
    public static function bindFromContext(FlowContext $context): void
    {
        $tenantId = $context->input['tenant_id'] ?? null;
        if (! is_string($tenantId) || $tenantId === '') {
            throw new FlowInputException(
                'StepTenantBinder: input["tenant_id"] is required for tenant-aware Flow steps (R30/R31). '
                .'Got: '.(is_string($tenantId) ? "''" : gettype($tenantId)).'.'
            );
        }
        /** @var TenantContext $ctx */
        $ctx = app(TenantContext::class);
        try {
            $ctx->set($tenantId);
        } catch (\InvalidArgumentException $e) {
            throw new FlowInputException(
                'StepTenantBinder: invalid tenant_id format on FlowContext input — '.$e->getMessage(),
                previous: $e,
            );
        }
    }
}

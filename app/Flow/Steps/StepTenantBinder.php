<?php

declare(strict_types=1);

namespace App\Flow\Steps;

use App\Support\TenantContext;
use Padosoft\LaravelFlow\FlowContext;

/**
 * Shared bootstrap for every kb.* Flow step.
 *
 * R30/R31 — every tenant-aware Eloquent query must run under the right
 * TenantContext. The Flow engine is tenant-agnostic, so we carry the
 * tenant_id through {@see FlowContext::$input} (key `tenant_id`) and
 * fall back to {@see FlowContext::$flowRunId}'s correlationId not being
 * available here — the only authoritative source the handler can see is
 * the input map, so callers MUST set `input['tenant_id']` when they
 * dispatch a flow that touches tenant-aware tables.
 *
 * IngestDocumentJob does this in `handle()` before it calls
 * `Flow::execute('kb.ingest', $input, $options)`.
 */
final class StepTenantBinder
{
    public static function bindFromContext(FlowContext $context): void
    {
        $tenantId = $context->input['tenant_id'] ?? null;
        if (! is_string($tenantId) || $tenantId === '') {
            return;
        }
        /** @var TenantContext $ctx */
        $ctx = app(TenantContext::class);
        $ctx->set($tenantId);
    }
}

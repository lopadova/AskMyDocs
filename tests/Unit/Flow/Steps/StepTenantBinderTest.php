<?php

declare(strict_types=1);

namespace Tests\Unit\Flow\Steps;

use App\Flow\Steps\StepTenantBinder;
use App\Support\TenantContext;
use Padosoft\LaravelFlow\Exceptions\FlowInputException;
use Padosoft\LaravelFlow\FlowContext;
use Tests\TestCase;

/**
 * Unit coverage for {@see StepTenantBinder::bindFromContext()}.
 *
 * Per Copilot PR #115 review iteration 1 (fix #5): R30/R31 demand FAIL
 * LOUD on missing or malformed tenant_id input. The previous silent
 * no-op behaviour meant a missing tenant_id let the step run under the
 * worker's default-tenant context, mis-attributing tenant-aware writes.
 */
final class StepTenantBinderTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->app->make(TenantContext::class)->reset();
        parent::tearDown();
    }

    public function test_throws_when_tenant_id_key_is_absent(): void
    {
        $context = new FlowContext(
            flowRunId: 'unit-run-1',
            definitionName: 'kb.ingest',
            input: ['project_key' => 'demo'], // no 'tenant_id' key
        );

        $this->expectException(FlowInputException::class);
        $this->expectExceptionMessage('input["tenant_id"] is required');

        StepTenantBinder::bindFromContext($context);
    }

    public function test_throws_when_tenant_id_is_empty_string(): void
    {
        $context = new FlowContext(
            flowRunId: 'unit-run-2',
            definitionName: 'kb.ingest',
            input: ['tenant_id' => ''],
        );

        $this->expectException(FlowInputException::class);

        StepTenantBinder::bindFromContext($context);
    }

    public function test_throws_when_tenant_id_is_not_a_string(): void
    {
        $context = new FlowContext(
            flowRunId: 'unit-run-3',
            definitionName: 'kb.ingest',
            input: ['tenant_id' => 12345], // int — TenantContext expects string
        );

        $this->expectException(FlowInputException::class);

        StepTenantBinder::bindFromContext($context);
    }

    public function test_throws_when_tenant_id_format_is_invalid_uppercase(): void
    {
        $context = new FlowContext(
            flowRunId: 'unit-run-4',
            definitionName: 'kb.ingest',
            input: ['tenant_id' => 'TenantA'], // uppercase rejected by TenantContext::set()
        );

        $this->expectException(FlowInputException::class);
        $this->expectExceptionMessage('invalid tenant_id format');

        StepTenantBinder::bindFromContext($context);
    }

    public function test_throws_when_tenant_id_format_is_too_long(): void
    {
        $context = new FlowContext(
            flowRunId: 'unit-run-5',
            definitionName: 'kb.ingest',
            // 51 chars — TenantContext::set() caps at 50.
            input: ['tenant_id' => str_repeat('a', 51)],
        );

        $this->expectException(FlowInputException::class);
        $this->expectExceptionMessage('invalid tenant_id format');

        StepTenantBinder::bindFromContext($context);
    }

    public function test_happy_path_sets_tenant_context_when_input_is_valid(): void
    {
        $tenantContext = $this->app->make(TenantContext::class);
        $tenantContext->reset();
        $this->assertSame('default', $tenantContext->current());

        $context = new FlowContext(
            flowRunId: 'unit-run-happy',
            definitionName: 'kb.ingest',
            input: ['tenant_id' => 'tenant-a'],
        );

        StepTenantBinder::bindFromContext($context);

        $this->assertSame(
            'tenant-a',
            $this->app->make(TenantContext::class)->current(),
            'Valid tenant_id input must rebind the singleton TenantContext.',
        );
    }
}

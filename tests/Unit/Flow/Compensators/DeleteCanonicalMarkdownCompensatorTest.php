<?php

declare(strict_types=1);

namespace Tests\Unit\Flow\Compensators;

use App\Flow\Compensators\DeleteCanonicalMarkdownCompensator;
use Illuminate\Support\Facades\Storage;
use Padosoft\LaravelFlow\FlowContext;
use Padosoft\LaravelFlow\FlowStepResult;
use Tests\TestCase;

final class DeleteCanonicalMarkdownCompensatorTest extends TestCase
{
    public function test_removes_existing_canonical_file_from_disk(): void
    {
        Storage::fake('kb');
        Storage::disk('kb')->put('decisions/dec-x.md', '---' . PHP_EOL . 'slug: dec-x' . PHP_EOL . '---');

        $compensator = $this->app->make(DeleteCanonicalMarkdownCompensator::class);
        $compensator->compensate(
            $this->context(),
            FlowStepResult::success([
                'relative_path' => 'decisions/dec-x.md',
                'disk' => 'kb',
            ]),
        );

        Storage::disk('kb')->assertMissing('decisions/dec-x.md');
    }

    public function test_idempotent_when_file_already_gone(): void
    {
        Storage::fake('kb');

        $compensator = $this->app->make(DeleteCanonicalMarkdownCompensator::class);
        // Should not throw — file is missing, treat as already cleaned.
        $compensator->compensate(
            $this->context(),
            FlowStepResult::success([
                'relative_path' => 'decisions/dec-x.md',
                'disk' => 'kb',
            ]),
        );

        $this->assertTrue(true);
    }

    public function test_no_op_on_empty_relative_path(): void
    {
        Storage::fake('kb');

        $compensator = $this->app->make(DeleteCanonicalMarkdownCompensator::class);
        $compensator->compensate(
            $this->context(),
            FlowStepResult::success([]),
        );

        $this->assertTrue(true);
    }

    private function context(): FlowContext
    {
        return new FlowContext(
            flowRunId: 'cm-test',
            definitionName: 'kb.promote',
            input: ['tenant_id' => 'default'],
            stepOutputs: [],
            dryRun: false,
        );
    }
}

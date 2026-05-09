<?php

declare(strict_types=1);

namespace Tests\Unit\Flow\Steps\Delete;

use App\Flow\Steps\Delete\RemoveSourceFileStep;
use Illuminate\Support\Facades\Storage;
use Padosoft\LaravelFlow\FlowContext;
use Tests\TestCase;

final class RemoveSourceFileStepTest extends TestCase
{
    public function test_removes_existing_file(): void
    {
        Storage::fake('kb');
        Storage::disk('kb')->put('docs/x.md', '# x');

        $step = $this->app->make(RemoveSourceFileStep::class);
        $result = $step->execute($this->context(force: true, keepFile: false, hardDeleted: true));

        $this->assertTrue($result->success);
        $this->assertTrue($result->output['file_deleted']);
        Storage::disk('kb')->assertMissing('docs/x.md');
    }

    public function test_no_op_when_keep_file_true(): void
    {
        Storage::fake('kb');
        Storage::disk('kb')->put('docs/x.md', '# x');

        $step = $this->app->make(RemoveSourceFileStep::class);
        $result = $step->execute($this->context(force: true, keepFile: true, hardDeleted: true));

        $this->assertTrue($result->output['skipped']);
        Storage::disk('kb')->assertExists('docs/x.md');
    }

    public function test_no_op_when_force_false(): void
    {
        Storage::fake('kb');
        Storage::disk('kb')->put('docs/x.md', '# x');

        $step = $this->app->make(RemoveSourceFileStep::class);
        $result = $step->execute($this->context(force: false, keepFile: false, hardDeleted: false));

        $this->assertTrue($result->output['skipped']);
        Storage::disk('kb')->assertExists('docs/x.md');
    }

    public function test_dry_run_skipped(): void
    {
        Storage::fake('kb');
        Storage::disk('kb')->put('docs/x.md', '# x');

        $step = $this->app->make(RemoveSourceFileStep::class);
        $result = $step->execute($this->context(force: true, keepFile: false, hardDeleted: true, dryRun: true));

        $this->assertTrue($result->dryRunSkipped);
        Storage::disk('kb')->assertExists('docs/x.md');
    }

    private function context(bool $force, bool $keepFile, bool $hardDeleted, bool $dryRun = false): FlowContext
    {
        return new FlowContext(
            flowRunId: 'rm-test',
            definitionName: 'kb.delete',
            input: ['tenant_id' => 'default', 'force' => $force, 'keep_file' => $keepFile],
            stepOutputs: [
                'hard-delete-rows' => [
                    'hard_deleted' => $hardDeleted,
                    'disk' => 'kb',
                    'full_path' => 'docs/x.md',
                    'document_id' => 1,
                    'source_path' => 'docs/x.md',
                ],
            ],
            dryRun: $dryRun,
        );
    }
}

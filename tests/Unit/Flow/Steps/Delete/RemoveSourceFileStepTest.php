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
        $this->assertTrue($result->output['ocr_assets_deleted'], 'no `.ocr/` tree was there: nothing remains');
        $this->assertSame(['file_deleted' => true, 'ocr_assets_deleted' => true], $result->businessImpact);
        Storage::disk('kb')->assertMissing('docs/x.md');
    }

    /** v8.36 / ADR 0029 §6 — a `.ocr/` tree kept by the in-flight grace is reported, never hidden behind `file_deleted=true`. */
    public function test_reports_an_ocr_tree_the_grace_kept(): void
    {
        Storage::fake('kb');
        Storage::disk('kb')->put('docs/x.md', '# x');
        Storage::disk('kb')->put('docs/x.md.ocr/'.str_repeat('a', 64).'/result.json', '{}');

        $step = $this->app->make(RemoveSourceFileStep::class);
        $result = $step->execute($this->context(force: true, keepFile: false, hardDeleted: true));

        $this->assertTrue($result->output['file_deleted']);
        $this->assertFalse($result->output['ocr_assets_deleted'], 'the run is inside the in-flight grace: kept and reported');
        $this->assertFalse($result->businessImpact['ocr_assets_deleted']);
        Storage::disk('kb')->assertExists('docs/x.md.ocr/'.str_repeat('a', 64).'/result.json');
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

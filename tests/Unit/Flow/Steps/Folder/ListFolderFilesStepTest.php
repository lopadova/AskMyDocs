<?php

declare(strict_types=1);

namespace Tests\Unit\Flow\Steps\Folder;

use App\Flow\Steps\Folder\ListFolderFilesStep;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Storage;
use Padosoft\LaravelFlow\Exceptions\FlowInputException;
use Padosoft\LaravelFlow\FlowContext;
use Tests\TestCase;

final class ListFolderFilesStepTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('kb');
    }

    protected function tearDown(): void
    {
        $this->app->make(TenantContext::class)->reset();
        parent::tearDown();
    }

    public function test_lists_supported_files_in_flat_folder(): void
    {
        Storage::disk('kb')->put('docs/a.md', '# a');
        Storage::disk('kb')->put('docs/b.txt', 'b');
        Storage::disk('kb')->put('docs/skip.png', 'binary');

        $step = $this->app->make(ListFolderFilesStep::class);
        $result = $step->execute($this->context('default', 'docs'));

        $this->assertSame(2, $result->output['matched_count']);
    }

    public function test_recursive_walks_subdirectories(): void
    {
        Storage::disk('kb')->put('docs/a.md', '# a');
        Storage::disk('kb')->put('docs/sub/b.md', '# b');

        $step = $this->app->make(ListFolderFilesStep::class);
        $result = $step->execute($this->context('default', 'docs', recursive: true));

        $this->assertSame(2, $result->output['matched_count']);
    }

    public function test_limit_caps_results(): void
    {
        Storage::disk('kb')->put('docs/a.md', '# a');
        Storage::disk('kb')->put('docs/b.md', '# b');
        Storage::disk('kb')->put('docs/c.md', '# c');

        $step = $this->app->make(ListFolderFilesStep::class);
        $result = $step->execute($this->context('default', 'docs', limit: 2));

        $this->assertSame(2, $result->output['matched_count']);
    }

    public function test_throws_on_missing_disk(): void
    {
        $step = $this->app->make(ListFolderFilesStep::class);
        $context = new FlowContext(
            flowRunId: 'r',
            definitionName: 'kb.ingest-folder',
            input: ['tenant_id' => 'default', 'base_path' => 'docs'],
        );

        $this->expectException(\RuntimeException::class);
        $step->execute($context);
    }

    public function test_throws_on_missing_tenant_id(): void
    {
        $step = $this->app->make(ListFolderFilesStep::class);
        $context = new FlowContext(
            flowRunId: 'r',
            definitionName: 'kb.ingest-folder',
            input: ['disk' => 'kb', 'base_path' => 'docs'],
        );

        $this->expectException(FlowInputException::class);
        $step->execute($context);
    }

    public function test_explicit_extensions_scope_to_subset(): void
    {
        Storage::disk('kb')->put('docs/a.md', '# a');
        Storage::disk('kb')->put('docs/b.txt', 'b');

        $step = $this->app->make(ListFolderFilesStep::class);
        $result = $step->execute($this->context('default', 'docs', extensions: ['md']));

        $this->assertSame(1, $result->output['matched_count']);
    }

    private function context(
        string $tenantId,
        string $basePath,
        bool $recursive = false,
        int $limit = 0,
        ?array $extensions = null,
    ): FlowContext {
        $input = [
            'tenant_id' => $tenantId,
            'disk' => 'kb',
            'base_path' => $basePath,
            'recursive' => $recursive,
            'limit' => $limit,
        ];
        if ($extensions !== null) {
            $input['extensions'] = $extensions;
        }
        return new FlowContext(
            flowRunId: 'r',
            definitionName: 'kb.ingest-folder',
            input: $input,
        );
    }
}

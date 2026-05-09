<?php

declare(strict_types=1);

namespace Tests\Unit\Flow\Steps;

use App\Flow\Steps\ParseMarkdownStep;
use App\Models\KnowledgeDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Padosoft\LaravelFlow\FlowContext;
use RuntimeException;
use Tests\TestCase;

final class ParseMarkdownStepTest extends TestCase
{
    use RefreshDatabase;

    public function test_happy_path_reads_markdown_and_parses_canonical_when_present(): void
    {
        Storage::fake('kb');
        Storage::disk('kb')->put('docs/intro.md', "# Hello\n\nBody.");
        config()->set('kb.sources.disk', 'kb');
        config()->set('kb.sources.path_prefix', '');

        $step = $this->app->make(ParseMarkdownStep::class);
        $context = new FlowContext(
            flowRunId: 'test-run-1',
            definitionName: 'kb.ingest',
            input: [
                'tenant_id' => 'default',
                'project_key' => 'demo',
                'source_path' => 'docs/intro.md',
                'disk' => 'kb',
                'mime_type' => 'text/markdown',
                'metadata' => [],
            ],
        );

        $result = $step->execute($context);

        $this->assertTrue($result->success);
        $this->assertSame('demo', $result->output['project_key']);
        $this->assertSame('docs/intro.md', $result->output['source_path']);
        $this->assertStringContainsString('Hello', $result->output['markdown']);
        $this->assertNull($result->output['canonical']); // no frontmatter
    }

    public function test_failure_path_throws_when_file_missing(): void
    {
        Storage::fake('kb');
        config()->set('kb.sources.disk', 'kb');
        config()->set('kb.sources.path_prefix', '');

        $step = $this->app->make(ParseMarkdownStep::class);
        $context = new FlowContext(
            flowRunId: 'test-run-2',
            definitionName: 'kb.ingest',
            input: [
                'tenant_id' => 'default',
                'project_key' => 'demo',
                'source_path' => 'docs/missing.md',
                'disk' => 'kb',
                'mime_type' => 'text/markdown',
                'metadata' => [],
            ],
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('file not found');
        $step->execute($context);
    }

    public function test_dry_run_does_not_mutate_database(): void
    {
        Storage::fake('kb');
        Storage::disk('kb')->put('docs/intro.md', "# Hello\n\nBody.");
        config()->set('kb.sources.disk', 'kb');
        config()->set('kb.sources.path_prefix', '');

        $step = $this->app->make(ParseMarkdownStep::class);
        $context = new FlowContext(
            flowRunId: 'test-run-3',
            definitionName: 'kb.ingest',
            input: [
                'tenant_id' => 'default',
                'project_key' => 'demo',
                'source_path' => 'docs/intro.md',
                'disk' => 'kb',
                'mime_type' => 'text/markdown',
                'metadata' => [],
            ],
            dryRun: true,
        );

        $before = KnowledgeDocument::count();
        $result = $step->execute($context);
        $after = KnowledgeDocument::count();

        $this->assertTrue($result->success);
        $this->assertSame($before, $after, 'ParseMarkdownStep must not mutate KnowledgeDocument under dry-run.');
    }

    public function test_canonical_frontmatter_is_parsed_when_present(): void
    {
        Storage::fake('kb');
        $markdown = <<<'MD'
---
type: decision
status: accepted
slug: dec-cache-v2
id: dec-001
---
# Cache v2 decision

We pick LRU.
MD;
        Storage::disk('kb')->put('docs/dec.md', $markdown);
        config()->set('kb.sources.disk', 'kb');
        config()->set('kb.sources.path_prefix', '');

        $step = $this->app->make(ParseMarkdownStep::class);
        $context = new FlowContext(
            flowRunId: 'test-run-4',
            definitionName: 'kb.ingest',
            input: [
                'tenant_id' => 'default',
                'project_key' => 'demo',
                'source_path' => 'docs/dec.md',
                'disk' => 'kb',
                'mime_type' => 'text/markdown',
                'metadata' => [],
            ],
        );

        $result = $step->execute($context);

        $this->assertTrue($result->success);
        $this->assertNotNull($result->output['canonical']);
        $this->assertSame('decision', $result->output['canonical']['type']);
        $this->assertSame('dec-cache-v2', $result->output['canonical']['slug']);
    }
}

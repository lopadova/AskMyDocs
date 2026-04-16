<?php

namespace Tests\Feature\Commands;

use App\Jobs\IngestDocumentJob;
use App\Models\KnowledgeDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KbIngestFolderCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('kb.sources.disk', 'kb');
        config()->set('kb.sources.path_prefix', '');
        config()->set('kb.ingest.queue', 'kb-ingest');
        config()->set('kb.ingest.default_project', 'default');
    }

    public function test_walks_flat_folder_and_dispatches_one_job_per_markdown(): void
    {
        Queue::fake();
        Storage::fake('kb');
        Storage::disk('kb')->put('a.md', 'alpha');
        Storage::disk('kb')->put('b.md', 'bravo');
        Storage::disk('kb')->put('c.txt', 'charlie');

        $this->artisan('kb:ingest-folder', ['--project' => 'demo'])
            ->assertSuccessful();

        Queue::assertPushed(IngestDocumentJob::class, 2);
    }

    public function test_recursive_flag_walks_subdirectories(): void
    {
        Queue::fake();
        Storage::fake('kb');
        Storage::disk('kb')->put('top.md', 'x');
        Storage::disk('kb')->put('nested/deep/child.md', 'y');

        $this->artisan('kb:ingest-folder', [
            '--project' => 'demo',
            '--recursive' => true,
        ])->assertSuccessful();

        Queue::assertPushed(IngestDocumentJob::class, 2);
    }

    public function test_pattern_filter_excludes_other_extensions(): void
    {
        Queue::fake();
        Storage::fake('kb');
        Storage::disk('kb')->put('one.md', 'x');
        Storage::disk('kb')->put('two.markdown', 'y');
        Storage::disk('kb')->put('three.txt', 'z');

        $this->artisan('kb:ingest-folder', [
            '--project' => 'demo',
            '--pattern' => '*.md',
        ])->assertSuccessful();

        Queue::assertPushed(IngestDocumentJob::class, 1);
    }

    public function test_dry_run_dispatches_nothing(): void
    {
        Queue::fake();
        Storage::fake('kb');
        Storage::disk('kb')->put('a.md', 'x');
        Storage::disk('kb')->put('b.md', 'y');

        $this->artisan('kb:ingest-folder', [
            '--project' => 'demo',
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('DRY-RUN')
            ->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_limit_caps_the_number_of_dispatches(): void
    {
        Queue::fake();
        Storage::fake('kb');
        for ($i = 0; $i < 5; $i++) {
            Storage::disk('kb')->put("f{$i}.md", "file {$i}");
        }

        $this->artisan('kb:ingest-folder', [
            '--project' => 'demo',
            '--limit' => 2,
        ])->assertSuccessful();

        Queue::assertPushed(IngestDocumentJob::class, 2);
    }

    public function test_sync_mode_runs_inline_without_touching_the_queue(): void
    {
        Queue::fake();
        Storage::fake('kb');
        Storage::disk('kb')->put('a.md', "# Hi\n\nBody.");

        config()->set('kb.embedding_cache.enabled', false);
        config()->set('ai.default', 'openai');
        config()->set('ai.embeddings_provider', 'openai');

        Http::fake([
            'api.openai.com/*' => function ($request) {
                $inputs = $request->data()['input'] ?? [];
                $data = [];
                foreach ($inputs as $i => $_text) {
                    $data[] = ['index' => $i, 'embedding' => [0.1, 0.2, 0.3]];
                }

                return Http::response([
                    'model' => 'text-embedding-3-small',
                    'data' => $data,
                    'usage' => ['total_tokens' => count($inputs)],
                ], 200);
            },
        ]);

        $this->artisan('kb:ingest-folder', [
            '--project' => 'demo',
            '--sync' => true,
        ])->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertSame(1, KnowledgeDocument::count());
    }

    public function test_empty_folder_returns_success_with_warning(): void
    {
        Queue::fake();
        Storage::fake('kb');

        $this->artisan('kb:ingest-folder', ['--project' => 'demo'])
            ->expectsOutputToContain('No markdown files matched')
            ->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_default_project_key_comes_from_config_when_option_missing(): void
    {
        Queue::fake();
        Storage::fake('kb');
        Storage::disk('kb')->put('x.md', 'x');

        config()->set('kb.ingest.default_project', 'fallback-project');

        $this->artisan('kb:ingest-folder')->assertSuccessful();

        Queue::assertPushed(IngestDocumentJob::class, function ($job) {
            return $job->projectKey === 'fallback-project';
        });
    }
}

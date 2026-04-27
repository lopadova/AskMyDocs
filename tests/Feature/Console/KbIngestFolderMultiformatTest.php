<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Ai\EmbeddingsResponse;
use App\Models\KnowledgeDocument;
use App\Services\Kb\EmbeddingCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\Fixtures\Docx\DocxFixtureBuilder;
use Tests\Fixtures\Pdf\PdfFixtureBuilder;
use Tests\TestCase;

final class KbIngestFolderMultiformatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Stub the embedding cache so tests don't need a real provider.
        $cache = Mockery::mock(EmbeddingCacheService::class);
        $cache->shouldReceive('generate')->andReturnUsing(
            fn (array $texts) => new EmbeddingsResponse(
                embeddings: array_map(fn () => array_fill(0, 768, 0.0), $texts),
                provider: 'fake',
                model: 'fake-768',
            ),
        );
        $this->app->instance(EmbeddingCacheService::class, $cache);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_folder_walker_picks_up_md_pdf_docx_with_correct_source_types(): void
    {
        Storage::fake('kb');
        Storage::disk('kb')->put('docs/a.md', "# Hello\n\nbody.");
        Storage::disk('kb')->put('docs/b.pdf', PdfFixtureBuilder::buildSinglePage('PDF body.'));
        Storage::disk('kb')->put('docs/c.docx', DocxFixtureBuilder::buildHeadingsSample());

        Artisan::call('kb:ingest-folder', [
            'path' => 'docs',
            '--project' => 'multiformat-test',
            '--sync' => true,
        ]);

        $docs = KnowledgeDocument::query()
            ->where('project_key', 'multiformat-test')
            ->orderBy('source_path')
            ->get();

        $this->assertCount(3, $docs);

        $byPath = $docs->keyBy('source_path');
        $this->assertSame('markdown', $byPath['docs/a.md']->source_type);
        $this->assertSame('pdf', $byPath['docs/b.pdf']->source_type);
        $this->assertSame('docx', $byPath['docs/c.docx']->source_type);

        $this->assertSame('text/markdown', $byPath['docs/a.md']->mime_type);
        $this->assertSame('application/pdf', $byPath['docs/b.pdf']->mime_type);
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            $byPath['docs/c.docx']->mime_type,
        );
    }

    public function test_folder_walker_default_pattern_includes_pdf_and_docx(): void
    {
        // Without an explicit `--pattern` the walker should pick up every
        // supported extension (T1.8 default broadened).
        Storage::fake('kb');
        Storage::disk('kb')->put('docs/x.txt', 'plain text');
        Storage::disk('kb')->put('docs/y.pdf', PdfFixtureBuilder::buildSinglePage('PDF body.'));

        Artisan::call('kb:ingest-folder', [
            'path' => 'docs',
            '--project' => 'default-pattern-test',
            '--sync' => true,
        ]);

        $count = KnowledgeDocument::query()
            ->where('project_key', 'default-pattern-test')
            ->count();
        $this->assertSame(2, $count);
    }

    public function test_folder_walker_explicit_pattern_still_scopes_to_subset(): void
    {
        // Operators can still pass `--pattern=md` to scope to a single
        // format (back-compat with pre-T1.8 default).
        Storage::fake('kb');
        Storage::disk('kb')->put('docs/a.md', '# md');
        Storage::disk('kb')->put('docs/b.pdf', PdfFixtureBuilder::buildSinglePage('pdf'));

        Artisan::call('kb:ingest-folder', [
            'path' => 'docs',
            '--project' => 'scoped-test',
            '--sync' => true,
            '--pattern' => 'md',
        ]);

        $count = KnowledgeDocument::query()->where('project_key', 'scoped-test')->count();
        $this->assertSame(1, $count, 'expected only the md file to ingest under --pattern=md');
    }
}

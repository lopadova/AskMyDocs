<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\Code;

use App\Services\Kb\Chunkers\CodeAstChunker;
use App\Services\Kb\Code\CodeLanguageMap;
use App\Services\Kb\Converters\CodePassthroughConverter;
use App\Services\Kb\Pipeline\ChunkDraft;
use App\Services\Kb\Pipeline\ConvertedDocument;
use App\Services\Kb\Pipeline\SourceDocument;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CodeAstChunkerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('kb.code.chunker_driver', 'heuristic');
        config()->set('kb.chunking.target_tokens', 120);
        config()->set('kb.chunking.hard_cap_tokens', 200);
        config()->set('kb.chunking.overlap_tokens', 16);
    }

    /**
     * @return list<ChunkDraft>
     */
    private function chunk(string $path, string $bytes): array
    {
        $mime = (string) CodeLanguageMap::mimeForPath($path);
        $converted = $this->app->make(CodePassthroughConverter::class)->convert(
            new SourceDocument($path, $mime, $bytes, null, null, 'local', []),
        );
        $doc = new ConvertedDocument(
            markdown: $converted->markdown,
            mediaItems: [],
            extractionMeta: array_merge($converted->extractionMeta, ['source_type' => 'code']),
            sourceMimeType: $mime,
        );

        return $this->app->make(CodeAstChunker::class)->chunk($doc);
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__.'/../../../Fixtures/code/'.$name);
    }

    /** A PHP class with N methods, each ~15 lines. */
    private function bigClass(int $methods): string
    {
        $out = "<?php\n\nnamespace App;\n\nclass Big\n{\n";
        for ($i = 1; $i <= $methods; $i++) {
            $out .= "    public function method{$i}(int \$a): int\n    {\n";
            for ($j = 0; $j < 8; $j++) {
                $out .= "        \$a = \$a * {$j} + {$i}; // step {$j} of method {$i}\n";
            }
            $out .= "        return \$a;\n    }\n\n";
        }

        return $out."}\n";
    }

    #[Test]
    public function a_small_file_is_one_chunk_named_after_its_first_symbol(): void
    {
        config()->set('kb.chunking.target_tokens', 512);
        config()->set('kb.chunking.hard_cap_tokens', 1024);
        $drafts = $this->chunk('src/Billing/Invoice.php', $this->fixture('Invoice.php'));

        $this->assertCount(1, $drafts);
        $d = $drafts[0];
        $this->assertSame('src/Billing/Invoice.php > Invoice', $d->headingPath);
        $this->assertSame('php', $d->metadata['language']);
        $this->assertSame('code', $d->metadata['source_type']);
        $this->assertSame('heuristic', $d->metadata['chunker']);
        $this->assertSame(1, $d->metadata['start_line']);
        $this->assertSame(39, $d->metadata['end_line']);
        $this->assertContains('Invoice > total', $d->metadata['symbols']);
        // Raw code, not a fenced block.
        $this->assertStringStartsWith('<?php', $d->text);
        $this->assertStringNotContainsString('```', $d->text);
    }

    #[Test]
    public function a_big_class_is_cut_on_method_boundaries_never_inside_a_method(): void
    {
        $drafts = $this->chunk('src/Big.php', $this->bigClass(14));

        $this->assertGreaterThan(3, count($drafts));
        foreach ($drafts as $i => $d) {
            if ($i === 0) {
                continue;
            }
            // Every chunk after the first starts at a method declaration.
            $this->assertMatchesRegularExpression('/^\s*public function method\d+/', $d->text, "chunk {$i} must start on a method boundary");
            $this->assertMatchesRegularExpression('/^src\/Big\.php > Big > method\d+/', $d->headingPath);
        }
    }

    #[Test]
    public function chunks_respect_the_hard_cap_and_cover_every_line_in_order(): void
    {
        $source = $this->bigClass(14);
        $drafts = $this->chunk('src/Big.php', $source);
        $lineCount = count(explode("\n", rtrim($source, "\n")));

        $previousEnd = 0;
        foreach ($drafts as $i => $d) {
            $this->assertLessThanOrEqual(200 + 8, (int) ceil(strlen($d->text) / 4), "chunk {$i} exceeds the hard cap");
            $this->assertSame($i, $d->order);
            // No gap between consecutive chunks (overlap is allowed, a hole is not).
            $this->assertLessThanOrEqual($previousEnd + 1, $d->metadata['start_line']);
            $previousEnd = $d->metadata['end_line'];
        }
        $this->assertSame($lineCount, $previousEnd);
        $this->assertSame(1, $drafts[0]->metadata['start_line']);
    }

    #[Test]
    public function a_single_huge_function_is_hard_cut_with_tail_overlap_and_keeps_its_symbol(): void
    {
        config()->set('kb.chunking.overlap_tokens', 50); // ≈ 2-3 of these ~20-token lines
        $body = '';
        for ($i = 0; $i < 120; $i++) {
            $body .= "    \$x{$i} = compute({$i}); // a long straight-line body with no blank lines\n";
        }
        $source = "<?php\n\nfunction giant(): void\n{\n{$body}}\n";
        $drafts = $this->chunk('src/giant.php', $source);

        $this->assertGreaterThan(2, count($drafts));
        foreach ($drafts as $d) {
            $this->assertSame('src/giant.php > giant', $d->headingPath, 'every slice of the function carries its symbol');
        }
        // Tail overlap: the next chunk starts BEFORE the previous one ended.
        $this->assertLessThanOrEqual($drafts[0]->metadata['end_line'], $drafts[1]->metadata['start_line']);
    }

    #[Test]
    public function overlap_zero_means_no_overlap(): void
    {
        config()->set('kb.chunking.overlap_tokens', 0);
        $body = '';
        for ($i = 0; $i < 120; $i++) {
            $body .= "    \$x{$i} = compute({$i}); // a long straight-line body with no blank lines\n";
        }
        $drafts = $this->chunk('src/giant.php', "<?php\n\nfunction giant(): void\n{\n{$body}}\n");

        for ($i = 1; $i < count($drafts); $i++) {
            $this->assertSame($drafts[$i - 1]->metadata['end_line'] + 1, $drafts[$i]->metadata['start_line']);
        }
    }

    #[Test]
    public function chunking_is_idempotent(): void
    {
        $source = $this->bigClass(10);
        $a = $this->chunk('src/Big.php', $source);
        $b = $this->chunk('src/Big.php', $source);

        $this->assertEquals($a, $b);
        $this->assertSame(
            array_map(static fn (ChunkDraft $d): string => hash('sha256', $d->text), $a),
            array_map(static fn (ChunkDraft $d): string => hash('sha256', $d->text), $b),
        );
    }

    #[Test]
    public function the_converter_fence_survives_code_that_contains_backticks(): void
    {
        $source = "<?php\n\$md = <<<MD\n```php\necho 1;\n```\nMD;\nfunction f() {}\n";
        $mime = (string) CodeLanguageMap::mimeForPath('src/x.php');
        $converted = $this->app->make(CodePassthroughConverter::class)->convert(new SourceDocument('src/x.php', $mime, $source, null, null, 'local', []));

        $this->assertStringStartsWith('````php', $converted->markdown, 'fence is longer than the longest backtick run');
        $drafts = $this->chunk('src/x.php', $source);
        $this->assertStringContainsString('```php', implode("\n", array_map(static fn (ChunkDraft $d): string => $d->text, $drafts)));
    }

    #[Test]
    public function line_numbers_are_source_lines_and_crlf_is_normalised(): void
    {
        $drafts = $this->chunk('src/a.php', "<?php\r\n\r\nfunction a() {}\r\n");

        $this->assertCount(1, $drafts);
        $this->assertSame(1, $drafts[0]->metadata['start_line']);
        $this->assertSame(3, $drafts[0]->metadata['end_line']);
        $this->assertStringNotContainsString("\r", $drafts[0]->text);
    }

    #[Test]
    public function empty_and_whitespace_sources_produce_no_chunks(): void
    {
        $this->assertSame([], $this->chunk('src/empty.php', ''));
        $this->assertSame([], $this->chunk('src/blank.php', "  \n\n  \n"));
    }

    #[Test]
    public function it_supports_only_the_code_source_type(): void
    {
        $chunker = $this->app->make(CodeAstChunker::class);

        $this->assertTrue($chunker->supports('code'));
        foreach (['markdown', 'text', 'pdf', 'docx', 'image', 'notion', 'jira'] as $other) {
            $this->assertFalse($chunker->supports($other));
        }
    }

    #[Test]
    public function a_document_without_a_language_fails_loudly(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->app->make(CodeAstChunker::class)->chunk(new ConvertedDocument("```\nfoo\n```\n", [], ['source_type' => 'code'], 'text/plain'));
    }

    #[Test]
    public function typescript_python_and_go_chunk_through_the_heuristic_driver(): void
    {
        $ts = $this->chunk('web/api.ts', "import { a } from 'x';\n\nexport class Api {\n  get() { return 1; }\n}\n");
        $py = $this->chunk('svc/repo.py', "import os\n\nclass Repo:\n    def find(self):\n        return 1\n");
        $go = $this->chunk('cmd/main.go', "package main\n\nfunc main() {}\n");

        $this->assertSame('typescript', $ts[0]->metadata['language']);
        $this->assertSame('python', $py[0]->metadata['language']);
        $this->assertSame('go', $go[0]->metadata['language']);
        $this->assertSame('web/api.ts > Api', $ts[0]->headingPath);
    }

    #[Test]
    public function a_single_line_over_the_hard_cap_is_split_on_the_shared_byte_budget_without_losing_text(): void
    {
        // hard cap 200 tokens ≈ 800 bytes; one minified-style line of ~3 KB.
        $blob = 'const DATA = ['.implode(',', array_map(fn ($i) => '"item-'.$i.'"', range(1, 300))).'];';
        $drafts = $this->chunk('src/data.js', "// generated table\n".$blob."\n");

        $split = array_values(array_filter($drafts, fn (ChunkDraft $d) => isset($d->metadata['split'])));
        $this->assertGreaterThan(1, count($split), 'the oversized line became several chunks');
        foreach ($drafts as $d) {
            $this->assertLessThanOrEqual(200 * 4, strlen($d->text), 'no chunk exceeds the hard cap');
        }
        $this->assertSame(count($split), $split[0]->metadata['split']['of']);
        $this->assertSame(1, $split[0]->metadata['split']['part']);
        $this->assertStringContainsString(implode('', array_map(fn (ChunkDraft $d) => $d->text, $split)), $blob."\n".'', 'concatenated parts are the original line, nothing dropped');
        $orders = array_map(fn (ChunkDraft $d) => $d->order, $drafts);
        $this->assertSame(range(0, count($drafts) - 1), $orders, 'chunk orders stay contiguous');
    }
}

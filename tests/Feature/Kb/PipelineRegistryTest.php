<?php

declare(strict_types=1);

namespace Tests\Feature\Kb;

use App\Services\Kb\Contracts\ChunkerInterface;
use App\Services\Kb\Contracts\ConverterInterface;
use App\Services\Kb\Converters\MarkdownPassthroughConverter;
use App\Services\Kb\Converters\TextPassthroughConverter;
use App\Services\Kb\MarkdownChunker;
use App\Services\Kb\Pipeline\PipelineRegistry;
use Tests\TestCase;

final class PipelineRegistryTest extends TestCase
{
    public function test_resolves_markdown_converter_by_mime(): void
    {
        $r = app(PipelineRegistry::class);
        $this->assertInstanceOf(MarkdownPassthroughConverter::class, $r->resolveConverter('text/markdown'));
        $this->assertInstanceOf(MarkdownPassthroughConverter::class, $r->resolveConverter('text/x-markdown'));
        $this->assertInstanceOf(TextPassthroughConverter::class, $r->resolveConverter('text/plain'));
    }

    public function test_throws_runtime_exception_on_unsupported_mime(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/No converter registered for MIME type: application\/octet-stream/');

        app(PipelineRegistry::class)->resolveConverter('application/octet-stream');
    }

    public function test_resolves_chunker_by_source_type(): void
    {
        $r = app(PipelineRegistry::class);
        $this->assertInstanceOf(MarkdownChunker::class, $r->resolveChunker('markdown'));
        $this->assertInstanceOf(MarkdownChunker::class, $r->resolveChunker('md'));
    }

    public function test_throws_runtime_exception_on_unsupported_source_type(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/No chunker registered for source type: pptx/');

        // `pptx` has no chunker registered (no PowerPoint converter exists yet);
        // before T1.x adds one, this must throw an actionable error.
        app(PipelineRegistry::class)->resolveChunker('pptx');
    }

    public function test_lists_all_registered_converters_for_admin_ui(): void
    {
        $r = app(PipelineRegistry::class);
        $names = collect($r->allConverters())->map(fn (ConverterInterface $c) => $c->name())->all();

        $this->assertContains('markdown-passthrough', $names);
        $this->assertContains('text-passthrough', $names);
        $this->assertContains('pdf-converter', $names);
        $this->assertContains('docx-converter', $names);
        $this->assertCount(4, $names);
    }

    public function test_lists_all_registered_chunkers_for_admin_ui(): void
    {
        $r = app(PipelineRegistry::class);
        $names = collect($r->allChunkers())->map(fn (ChunkerInterface $c) => $c->name())->all();

        $this->assertContains('markdown-section-aware', $names);
        $this->assertCount(1, $names);
    }

    public function test_enrichers_list_is_empty_in_v3_0(): void
    {
        // v3.1 will populate enrichers; in v3.0 the list is empty.
        $this->assertSame([], app(PipelineRegistry::class)->allEnrichers());
    }

    public function test_singleton_resolution_returns_same_instance_per_request(): void
    {
        $a = app(PipelineRegistry::class);
        $b = app(PipelineRegistry::class);

        $this->assertSame($a, $b, 'PipelineRegistry must be bound as a singleton — boot cost is paid once.');
    }

    public function test_boot_throws_when_configured_converter_does_not_implement_contract(): void
    {
        // Replace the kb-pipeline config with a misconfigured FQCN (a class that
        // exists but doesn't implement ConverterInterface) and force a fresh
        // singleton boot. Without the fail-loud guard, this would silently boot
        // and only blow up at the first `supports()` call.
        config()->set('kb-pipeline.converters', [\stdClass::class]);
        $this->app->forgetInstance(PipelineRegistry::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches(
            '/Pipeline class "stdClass" does not implement App\\\\Services\\\\Kb\\\\Contracts\\\\ConverterInterface/',
        );

        app(PipelineRegistry::class);
    }

    public function test_boot_throws_when_configured_chunker_does_not_implement_contract(): void
    {
        config()->set('kb-pipeline.chunkers', [\stdClass::class]);
        $this->app->forgetInstance(PipelineRegistry::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches(
            '/Pipeline class "stdClass" does not implement App\\\\Services\\\\Kb\\\\Contracts\\\\ChunkerInterface/',
        );

        app(PipelineRegistry::class);
    }

    public function test_boot_throws_when_configured_enricher_does_not_implement_contract(): void
    {
        config()->set('kb-pipeline.enrichers', [\stdClass::class]);
        $this->app->forgetInstance(PipelineRegistry::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches(
            '/Pipeline class "stdClass" does not implement App\\\\Services\\\\Kb\\\\Contracts\\\\EnricherInterface/',
        );

        app(PipelineRegistry::class);
    }
}

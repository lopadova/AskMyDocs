<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\Code;

use App\Services\Kb\Chunkers\CodeAstChunker;
use App\Services\Kb\Code\CodeLanguageMap;
use App\Services\Kb\Contracts\ChunkerInterface;
use App\Services\Kb\Contracts\ConverterInterface;
use App\Services\Kb\Pipeline\PipelineRegistry;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * R23 — the code content class is wired into the registry exactly once and the
 * static MIME map in config/kb-pipeline.php stays in lock-step with
 * CodeLanguageMap (the enum, the converter and the entry points read the map;
 * the registry reads the config).
 */
final class CodePipelineConfigParityTest extends TestCase
{
    #[Test]
    public function mime_to_source_type_lists_exactly_the_code_mimes(): void
    {
        $map = (array) config('kb-pipeline.mime_to_source_type');
        $codeMimes = array_keys(array_filter($map, static fn ($t): bool => $t === 'code'));
        sort($codeMimes);
        $expected = CodeLanguageMap::mimes();
        sort($expected);

        $this->assertSame($expected, $codeMimes);
    }

    #[Test]
    public function every_code_mime_resolves_to_the_code_converter_and_chunker(): void
    {
        /** @var PipelineRegistry $registry */
        $registry = $this->app->make(PipelineRegistry::class);
        foreach (CodeLanguageMap::mimes() as $mime) {
            $converter = $registry->resolveConverter($mime);
            $this->assertInstanceOf(ConverterInterface::class, $converter);
            $this->assertSame('code-passthrough', $converter->name());
        }
        $this->assertInstanceOf(CodeAstChunker::class, $registry->resolveChunker('code'));
        $this->assertInstanceOf(ChunkerInterface::class, $registry->resolveChunker('code'));
    }

    #[Test]
    public function no_prose_converter_claims_a_code_mime(): void
    {
        /** @var PipelineRegistry $registry */
        $registry = $this->app->make(PipelineRegistry::class);
        foreach (CodeLanguageMap::mimes() as $mime) {
            $claimers = [];
            foreach ($registry->allConverters() as $converter) {
                if ($converter->supports($mime)) {
                    $claimers[] = $converter->name();
                }
            }
            $this->assertSame(['code-passthrough'], $claimers, "MIME {$mime} must be claimed by exactly the code converter");
        }
    }
}

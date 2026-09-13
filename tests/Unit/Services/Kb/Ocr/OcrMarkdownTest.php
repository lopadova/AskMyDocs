<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Kb\Ocr;

use App\Services\Kb\Ocr\OcrMarkdown;
use PHPUnit\Framework\TestCase;

/**
 * ADR 0029 — one rule for every driver: the persisted Markdown may cite only
 * the figures the service stores; every other image link becomes text, so a
 * remote response or a model can never make a document load an arbitrary
 * URL or a file that is not there (SEC-LLM-001 gate 6 / SEC-EXTRESP-001).
 */
final class OcrMarkdownTest extends TestCase
{
    public function test_generated_figure_links_are_kept_and_every_other_image_link_becomes_text(): void
    {
        $in = "Intro\n\n![Figure 1.1](images/fig-1-1.jpg)\n\n![chart](https://evil.example/track.png)\n\n![](img-0.jpeg)\n\n![Figure](figure)\n\n[a link](https://example.test) stays";
        $out = OcrMarkdown::stripForeignImageLinks($in, ['fig-1-1.jpg']);

        $this->assertStringContainsString('![Figure 1.1](images/fig-1-1.jpg)', $out);
        $this->assertStringContainsString('*[Figure: chart]*', $out);
        $this->assertStringNotContainsString('evil.example', $out);
        $this->assertStringNotContainsString('img-0.jpeg', $out);
        $this->assertStringContainsString("*[Figure]*\n\n*[Figure]*", $out);
        $this->assertStringContainsString('[a link](https://example.test) stays', $out);
    }

    public function test_a_generated_looking_link_that_was_not_extracted_is_not_kept(): void
    {
        $this->assertSame('*[Figure 2.3]*', OcrMarkdown::stripForeignImageLinks('![Figure 2.3](images/fig-2-3.png)', ['fig-1-1.png']));
        $this->assertSame('*[Figure 2.3]*', OcrMarkdown::stripForeignImageLinks('![Figure 2.3](images/fig-2-3.png)', []));
    }
}

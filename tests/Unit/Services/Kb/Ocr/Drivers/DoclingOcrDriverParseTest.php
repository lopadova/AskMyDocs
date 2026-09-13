<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Kb\Ocr\Drivers;

use App\Services\Kb\Ocr\Drivers\DoclingOcrDriver;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * SEC-PATH-001 — the figure links in Docling's Markdown are OCR OUTPUT and
 * must never decide a filesystem read outside the working directory.
 */
final class DoclingOcrDriverParseTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/kb_docling_test_'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/input_artifacts', 0755, true);
        file_put_contents($this->dir.'/input_artifacts/image_1.png', 'PNGBYTES');
        file_put_contents($this->dir.'/secret.txt', 'host secret');
    }

    protected function tearDown(): void
    {
        // R7 — no `@`-silenced cleanup: a directory that cannot be removed
        // is reported (after the framework teardown, R41), never left behind
        // silently for the next run; an already-removed one is fine.
        $failed = [];
        foreach (glob($this->dir.'/input_artifacts/*') ?: [] as $f) {
            if (! unlink($f)) {
                $failed[] = $f;
            }
        }
        foreach (glob($this->dir.'/*') ?: [] as $f) {
            if (is_file($f) && ! unlink($f)) {
                $failed[] = $f;
            }
        }
        foreach ([$this->dir.'/input_artifacts', $this->dir] as $d) {
            if (is_dir($d) && ! rmdir($d)) {
                $failed[] = $d;
            }
        }
        parent::tearDown();
        if ($failed !== []) {
            throw new \RuntimeException('Test cleanup left artifacts behind: '.implode(', ', $failed));
        }
    }

    #[Test]
    public function a_well_formed_artifact_link_becomes_a_figure(): void
    {
        $pages = $this->app->make(DoclingOcrDriver::class)->parseMarkdownOutput(
            "Text\n\n![Figure](input_artifacts/image_1.png)\n\n<!-- page break -->\nSecond",
            $this->dir,
        );

        $this->assertCount(2, $pages);
        $this->assertCount(1, $pages[0]->figures);
        $this->assertSame('PNGBYTES', $pages[0]->figures[0]->bytes);
        $this->assertSame('png', $pages[0]->figures[0]->extension);
        $this->assertStringContainsString('](images/fig-1-1.png)', $pages[0]->markdown);
        $this->assertSame([], $pages[1]->figures);
    }

    /** The run's aggregate budget (KB_OCR_MAX_FIGURES / KB_OCR_MAX_FIGURES_TOTAL_BYTES): a figure past it is omitted before it is read, across pages. */
    #[Test]
    public function figures_past_the_run_budget_are_omitted_and_never_read(): void
    {
        file_put_contents($this->dir.'/input_artifacts/image_2.png', 'MOREBYTES');
        config(['kb.ocr.max_figures_per_run' => 1]);

        $pages = $this->app->make(DoclingOcrDriver::class)->parseMarkdownOutput(
            "![One](input_artifacts/image_1.png)\n\n<!-- page break -->\n![Two](input_artifacts/image_2.png)\n\nAfter",
            $this->dir,
        );

        $this->assertCount(1, $pages[0]->figures);
        $this->assertSame([], $pages[1]->figures, 'the second figure is past the count budget');
        $this->assertStringContainsString('figure budget reached', $pages[1]->markdown);
        $this->assertStringNotContainsString('](images/', $pages[1]->markdown);

        config(['kb.ocr.max_figures_per_run' => 10, 'kb.ocr.max_figures_total_bytes' => strlen('PNGBYTES') + 1]);
        $pages = $this->app->make(DoclingOcrDriver::class)->parseMarkdownOutput(
            "![One](input_artifacts/image_1.png)\n![Two](input_artifacts/image_2.png)",
            $this->dir,
        );
        $this->assertCount(1, $pages[0]->figures, 'the second figure is past the byte budget');
        $this->assertStringContainsString('figure budget reached', $pages[0]->markdown);
    }

    /** KB_OCR_MAX_FIGURE_BYTES holds for every driver: an over-cap figure is neither read nor stored, and the link is replaced by a note. */
    #[Test]
    public function a_figure_over_the_per_figure_cap_is_omitted_and_never_read(): void
    {
        config(['kb.ocr.max_figure_bytes' => 4]);

        $pages = $this->app->make(DoclingOcrDriver::class)->parseMarkdownOutput(
            "Text\n\n![Figure](input_artifacts/image_1.png)\n\nAfter",
            $this->dir,
        );

        $this->assertSame([], $pages[0]->figures);
        $this->assertStringNotContainsString('](images/', $pages[0]->markdown, 'no link to a figure that is not stored');
        $this->assertStringNotContainsString('input_artifacts/', $pages[0]->markdown);
        $this->assertStringContainsString('omitted', $pages[0]->markdown);
        $this->assertStringContainsString('After', $pages[0]->markdown);
    }

    #[Test]
    public function traversal_and_non_image_links_become_text_placeholders_and_are_never_read(): void
    {
        $markdown = implode("\n", [
            '![a](input_artifacts/../secret.txt)',
            '![b](input_artifacts/../../../../etc/hostname)',
            '![c](/etc/passwd)',
            '![d](input_artifacts/image_1.png.php)',
            '![e](secret.txt)',
        ]);

        $pages = $this->app->make(DoclingOcrDriver::class)->parseMarkdownOutput($markdown, $this->dir);

        $this->assertSame([], $pages[0]->figures, 'no figure may be read from a non-allow-listed link');
        // A rejected link is not kept as a link either: the persisted Markdown
        // never cites a path the store did not write (one rule, every driver).
        $this->assertSame(implode("\n", ['*[Figure: a]*', '*[Figure: b]*', '*[Figure: c]*', '*[Figure: d]*', '*[Figure: e]*']), $pages[0]->markdown);
        $this->assertStringNotContainsString('](', $pages[0]->markdown, 'no image link survives');
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Kb\Ocr\Drivers;

use App\Services\Kb\Ocr\Drivers\FakeOcrDriver;
use App\Services\Kb\Ocr\Drivers\TesseractOcrDriver;
use App\Services\Kb\Ocr\OcrLimitExceededException;
use App\Services\Kb\Ocr\OcrRequest;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A page whose engine process hits its timeout — the per-page timeout, or
 * what is left of the run budget — is the terminal `run_too_long`, never a
 * generic error the job re-runs three times.
 */
final class TesseractTimeoutTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        parent::tearDown();
    }

    #[Test]
    public function a_page_that_outlives_its_timeout_is_a_terminal_refusal(): void
    {
        $slow = (string) tempnam(sys_get_temp_dir(), 'tesseract_slow_');
        $this->tempFiles[] = $slow;
        file_put_contents($slow, "#!/bin/sh\nsleep 3\n");
        chmod($slow, 0755);
        config(['kb.ocr.tesseract.binary' => $slow, 'kb.ocr.tesseract.timeout' => 1, 'kb.ocr.job_timeout' => 600]);

        try {
            app(TesseractOcrDriver::class)->recognise(new OcrRequest((string) base64_decode(FakeOcrDriver::PNG_1X1, true), 'image/png', 'scan.png'));
            $this->fail('a page past its timeout must be refused');
        } catch (OcrLimitExceededException $e) {
            $this->assertSame('run_too_long', $e->reason);
            $this->assertStringContainsString('tesseract exceeded its timeout (1 s', $e->getMessage());
        }
        $this->assertSame([], glob(sys_get_temp_dir().'/kb_ocr_*/input.img') ?: [], 'the working directory is removed on refusal');
    }

    /** The confidence pass is soft, but a timeout there is the same terminal refusal — never a page recorded past the budget. */
    #[Test]
    public function a_confidence_pass_that_outlives_its_timeout_is_a_terminal_refusal_too(): void
    {
        $stub = (string) tempnam(sys_get_temp_dir(), 'tesseract_tsv_');
        $this->tempFiles[] = $stub;
        // Text pass (no `tsv` argument) answers at once; the TSV pass sleeps.
        file_put_contents($stub, "#!/bin/sh\nfor a in \"\$@\"; do [ \"\$a\" = tsv ] && sleep 3; done\necho recognised\n");
        chmod($stub, 0755);
        config(['kb.ocr.tesseract.binary' => $stub, 'kb.ocr.tesseract.timeout' => 1, 'kb.ocr.job_timeout' => 600]);

        try {
            app(TesseractOcrDriver::class)->recognise(new OcrRequest((string) base64_decode(FakeOcrDriver::PNG_1X1, true), 'image/png', 'scan.png'));
            $this->fail('a confidence pass past its timeout must be refused');
        } catch (OcrLimitExceededException $e) {
            $this->assertSame('run_too_long', $e->reason);
            $this->assertStringContainsString('confidence pass', $e->getMessage());
        }
    }
}

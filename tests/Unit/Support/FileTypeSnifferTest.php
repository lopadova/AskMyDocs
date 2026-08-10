<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Kb\FileTypeSniffer;
use App\Support\Kb\SourceType;
use PHPUnit\Framework\TestCase;

/**
 * Magic-byte content verification (SEC-UPLOAD-001). Uses real temp files so the
 * sniffer reads actual bytes, not a client-declared type.
 */
class FileTypeSnifferTest extends TestCase
{
    /** @var array<int, string> */
    private array $tmp = [];

    protected function tearDown(): void
    {
        foreach ($this->tmp as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    private function file(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sniff');
        file_put_contents($path, $bytes);
        $this->tmp[] = $path;

        return $path;
    }

    public function test_real_pdf_passes_pdf(): void
    {
        $this->assertNull(FileTypeSniffer::mismatchReason($this->file("%PDF-1.7\n%âãÏÓ\n"), SourceType::PDF));
    }

    public function test_non_pdf_declared_pdf_is_rejected(): void
    {
        $this->assertNotNull(FileTypeSniffer::mismatchReason($this->file('<html>not a pdf</html>'), SourceType::PDF));
    }

    public function test_zip_container_passes_docx(): void
    {
        $this->assertNull(FileTypeSniffer::mismatchReason($this->file("PK\x03\x04rest-of-zip"), SourceType::DOCX));
    }

    public function test_non_zip_declared_docx_is_rejected(): void
    {
        $this->assertNotNull(FileTypeSniffer::mismatchReason($this->file('%PDF-1.7 actually a pdf'), SourceType::DOCX));
    }

    public function test_real_markdown_passes_text(): void
    {
        $this->assertNull(FileTypeSniffer::mismatchReason($this->file("# Title\n\nSome text."), SourceType::MARKDOWN));
    }

    public function test_pdf_bytes_under_markdown_name_is_rejected(): void
    {
        $this->assertNotNull(FileTypeSniffer::mismatchReason($this->file("%PDF-1.7 hidden"), SourceType::MARKDOWN));
    }

    public function test_binary_zip_under_text_name_is_rejected(): void
    {
        $this->assertNotNull(FileTypeSniffer::mismatchReason($this->file("PK\x03\x04binary"), SourceType::TEXT));
    }

    public function test_nul_bytes_under_markdown_name_is_rejected(): void
    {
        $this->assertNotNull(FileTypeSniffer::mismatchReason($this->file("text\0with\0nul"), SourceType::MARKDOWN));
    }

    public function test_unreadable_path_reports_a_reason(): void
    {
        $this->assertNotNull(FileTypeSniffer::mismatchReason('/no/such/file/at/all', SourceType::PDF));
    }
}

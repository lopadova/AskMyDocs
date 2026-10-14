<?php

namespace Tests\Unit\Kb;

use App\Services\Kb\Chunking\Support\TextBudgetSplitter;
use App\Services\Kb\MarkdownChunker;
use Tests\TestCase;

class TextBudgetSplitterTest extends TestCase
{
    public function test_preserves_every_byte_of_long_multilingual_rows_and_unbroken_values(): void
    {
        foreach ([str_repeat('RU123 quantità €185 日本語 8056713334552 ', 1000), str_repeat('界', 1000)] as $text) {
            $pieces = (new TextBudgetSplitter)->split($text, 256);
            $this->assertGreaterThan(1, count($pieces));
            $this->assertSame($text, implode('', $pieces));
            foreach ($pieces as $piece) {
                $this->assertLessThanOrEqual(256, strlen($piece));
                $this->assertTrue(mb_check_encoding($piece, 'UTF-8'));
            }
        }
    }

    public function test_daily_email_table_stays_bounded_and_keeps_every_return(): void
    {
        config(['kb.chunking.hard_cap_tokens' => 1024, 'kb.chunking.overlap_tokens' => 0]);
        $rows = [];
        for ($i = 0; $i < 500; $i++) {
            $rows[] = "RU{$i} taglia errata ordine U{$i} €185,00 barcode 8056713334552";
        }
        $body = implode(' ', $rows);
        $chunks = (new MarkdownChunker)->chunkLegacy('daily-returns.md', "# Resi giornalieri\n\n    ".$body);
        $this->assertGreaterThan(1, $chunks->count());
        $joined = $chunks->pluck('text')->implode(' ');
        $this->assertSame($body, $joined);
        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(4096, strlen($chunk['text']));
            $this->assertSame('Resi giornalieri', $chunk['heading_path']);
        }
    }
}

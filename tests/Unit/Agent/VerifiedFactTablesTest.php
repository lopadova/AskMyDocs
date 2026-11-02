<?php

namespace Tests\Unit\Agent;

use App\Agent\VerifiedFactTables;
use Illuminate\Support\Str;
use Tests\TestCase;

final class VerifiedFactTablesTest extends TestCase
{
    public function test_source_tables_keep_their_rows_instead_of_becoming_an_escaped_nested_cell(): void
    {
        $claims = [
            ['text' => "| Pair | Finding |\n|---|---|\n| First | Reported defect |"],
            ['text' => "| Pair | Finding |\n|---|---|\n| Second | Damaged |"],
        ];
        $result = app(VerifiedFactTables::class)->render([
            ['text' => "| Item | Proposed finding |\n|---|---|\n| Both | Invented conclusion |", 'claim_ids' => [0, 1]],
        ], $claims, 'en');
        $html = Str::markdown($result[0]['text']);
        $this->assertSame(1, substr_count($html, '<table>'));
        $this->assertSame(4, substr_count($html, '<td>'));
        $this->assertStringContainsString('<td>First</td>', $html);
        $this->assertStringContainsString('<td>Second</td>', $html);
        $this->assertStringNotContainsString('Invented conclusion', $html);
        $this->assertStringNotContainsString('\\|', $result[0]['text']);
        $this->assertTrue($result[0]['literal_table']);
    }

    public function test_literal_cells_preserve_values_without_creating_columns_or_executing_source_html(): void
    {
        $claims = [['text' => "CASE-A: A | B\n<script>example()</script>"]];
        $result = app(VerifiedFactTables::class)->render([
            ['text' => "| Case | Status |\n|---|---|\n| CASE-A | Rejected candidate |", 'claim_ids' => [0]],
        ], $claims, 'en');
        $html = Str::markdown($result[0]['text']);
        $this->assertSame(2, substr_count($html, '<td>'));
        $this->assertStringContainsString('CASE-A: A | B', $html);
        $this->assertStringContainsString('&lt;script&gt;example()&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('Rejected candidate', $html);
    }
}

<?php

namespace Tests\Unit\Agent;

use App\Agent\VerifiedFactTables;
use Illuminate\Support\Str;
use Tests\TestCase;

final class VerifiedFactTablesTest extends TestCase
{
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

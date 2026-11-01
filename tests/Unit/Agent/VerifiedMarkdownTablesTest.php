<?php

namespace Tests\Unit\Agent;

use App\Agent\VerifiedMarkdownTables;
use Illuminate\Support\Str;
use Tests\TestCase;

final class VerifiedMarkdownTablesTest extends TestCase
{
    public function test_orphan_verified_rows_render_as_tables_without_inventing_labels_or_changing_values(): void
    {
        $text = "| Original shipment: ORDER-A | Listed on manifest. | Delivery unconfirmed. |\n\n| Return: RETURN-A | Label issued. | Pickup unconfirmed. |";
        $result = app(VerifiedMarkdownTables::class)->normalize($text);
        $html = Str::markdown($result);
        $this->assertSame(2, substr_count($html, '<table>'));
        foreach (explode("\n\n", $text) as $row) $this->assertStringContainsString($row, $result);
        $this->assertStringContainsString('<td>Pickup unconfirmed.</td>', $html);
        $this->assertSame($result, app(VerifiedMarkdownTables::class)->normalize($result));
    }

    public function test_complete_tables_with_or_without_outer_header_pipes_remain_unchanged(): void
    {
        foreach (["| Reference | Status |\n|---|---|\n| ORDER-A | Sent |",
            "Reference | Status\n--- | ---\n| ORDER-A | Sent |"] as $text) {
            $this->assertSame($text, app(VerifiedMarkdownTables::class)->normalize($text));
            $this->assertStringContainsString('<table>', Str::markdown($text));
        }
    }

    public function test_escaped_cell_pipes_do_not_create_extra_columns(): void
    {
        $result = app(VerifiedMarkdownTables::class)->normalize('| A \\| B | C |');
        $html = Str::markdown($result);
        $this->assertSame(2, substr_count($html, '<th>'));
        $this->assertStringContainsString('<td>A | B</td>', $html);
        $this->assertStringContainsString('<td>C</td>', $html);
    }

    public function test_code_fences_and_ordinary_pipe_prose_are_not_rewritten(): void
    {
        $text = "Text A | B.\n\n```text\n| A | B |\n```not a closing fence\n| C | D |\n```\n\n~~~text\n| E | F |\n~~~";
        $this->assertSame($text, app(VerifiedMarkdownTables::class)->normalize($text));
    }
}

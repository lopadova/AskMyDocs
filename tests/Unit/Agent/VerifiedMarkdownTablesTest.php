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
        $this->assertSame(1, substr_count($html, '<table>'));
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

    public function test_blank_lines_repeated_headers_and_missing_trailing_pipes_do_not_split_a_table(): void
    {
        $text = "| Case | Evidence |\n|---|---|\n| CASE-A | Ready\n\n| CASE-B | Pending\n\n| Case | Evidence |\n|---|---|\n| CASE-C | Unknown |";
        $result = app(VerifiedMarkdownTables::class)->normalize($text);
        $html = Str::markdown($result);
        $this->assertSame(1, substr_count($html, '<table>'));
        $this->assertSame(4, substr_count($html, '<tr>'));
        $this->assertSame(1, substr_count($result, '| Case | Evidence |'));
        foreach (['Ready', 'Pending', 'Unknown'] as $value) $this->assertStringContainsString('<td>'.$value.'</td>', $html);
        $this->assertSame($result, app(VerifiedMarkdownTables::class)->normalize($result));
    }

    public function test_single_column_rows_are_tables_but_setext_headings_are_not(): void
    {
        $text = "| First recorded event |\n\n| Second recorded event |";
        $html = Str::markdown(app(VerifiedMarkdownTables::class)->normalize($text));
        $this->assertSame(1, substr_count($html, '<table>'));
        $this->assertSame(2, substr_count($html, '<td>'));
        $heading = "Title\n---";
        $this->assertSame($heading, app(VerifiedMarkdownTables::class)->normalize($heading));
        $this->assertFalse(app(VerifiedMarkdownTables::class)->hasTable($heading));
        $this->assertFalse(app(VerifiedMarkdownTables::class)->hasTable("```\n| H |\n|---|\n| V |\n```"));
    }
}

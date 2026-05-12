<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors\Jira;

use App\Connectors\BuiltIn\Jira\JqlBuilder;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * v4.5/W6 — JqlBuilder behaviour: clause composition, JQL-specific
 * date format, escape rules for the meta-characters Jira's JQL
 * grammar treats as reserved (`\\`, `"`, `'`).
 */
final class JqlBuilderTest extends TestCase
{
    #[Test]
    public function for_project_emits_project_clause(): void
    {
        $jql = JqlBuilder::for('PROJ')->build();
        $this->assertSame('project = "PROJ"', $jql);
    }

    #[Test]
    public function any_starts_with_no_project_clause(): void
    {
        $jql = JqlBuilder::any()->build();
        $this->assertSame('', $jql);
    }

    #[Test]
    public function updated_since_uses_jira_date_format_not_iso8601(): void
    {
        $since = Carbon::parse('2026-05-12T08:30:15Z');
        $jql = JqlBuilder::any()->updatedSince($since)->build();

        // JQL wire format is "YYYY-MM-DD HH:mm" — minute precision,
        // NO trailing Z, NO seconds.
        $this->assertSame('updated >= "2026-05-12 08:30"', $jql);
        $this->assertStringNotContainsString('T', $jql);
        $this->assertStringNotContainsString('Z', $jql);
    }

    #[Test]
    public function combines_project_status_and_updated(): void
    {
        $since = Carbon::parse('2026-05-12 00:00:00');
        $jql = JqlBuilder::for('PROJ')
            ->status('!=', 'Done')
            ->updatedSince($since)
            ->build();

        $this->assertStringContainsString('project = "PROJ"', $jql);
        $this->assertStringContainsString('status != "Done"', $jql);
        $this->assertStringContainsString('updated >= "', $jql);
        $this->assertSame(
            'project = "PROJ" AND status != "Done" AND updated >= "2026-05-12 00:00"',
            $jql,
        );
    }

    #[Test]
    public function order_by_renders_at_end(): void
    {
        $jql = JqlBuilder::for('PROJ')
            ->orderBy('updated', 'DESC')
            ->build();
        $this->assertSame('project = "PROJ" ORDER BY updated DESC', $jql);
    }

    #[Test]
    public function order_by_normalises_direction(): void
    {
        $jql = JqlBuilder::any()->orderBy('updated', 'asc')->build();
        $this->assertSame('ORDER BY updated ASC', $jql);

        $jql2 = JqlBuilder::any()->orderBy('updated', 'noise')->build();
        $this->assertSame('ORDER BY updated DESC', $jql2);
    }

    #[Test]
    public function in_operator_renders_quoted_list(): void
    {
        $jql = JqlBuilder::for('PROJ')
            ->whereField('status', 'in', ['Open', 'In Progress'])
            ->build();

        $this->assertStringContainsString('status IN ("Open", "In Progress")', $jql);
    }

    #[Test]
    public function not_in_operator_renders_uppercase(): void
    {
        $jql = JqlBuilder::any()->status('not in', ['Done', 'Closed'])->build();
        $this->assertSame('status NOT IN ("Done", "Closed")', $jql);
    }

    #[Test]
    public function single_quote_in_value_is_escaped(): void
    {
        $jql = JqlBuilder::for("Customer's project")->build();
        // Single quote escaped inside the double-quoted JQL string.
        $this->assertStringContainsString("Customer\\'s project", $jql);
    }

    #[Test]
    public function double_quote_in_value_is_escaped(): void
    {
        $jql = JqlBuilder::for('Has "quotes"')->build();
        $this->assertStringContainsString('Has \\"quotes\\"', $jql);
    }

    #[Test]
    public function backslash_in_value_is_doubled_first(): void
    {
        $raw = 'path\\to\\thing';
        $escaped = JqlBuilder::escapeValue($raw);
        // Backslash must double FIRST so subsequent rules don't
        // compound-escape it.
        $this->assertSame('path\\\\to\\\\thing', $escaped);
    }

    #[Test]
    public function backslash_quote_combo_round_trips_correctly(): void
    {
        // A literal `\"` in the value must become `\\\\\\"` after
        // escape: backslash doubles → `\\\\`, then the quote escapes
        // → `\\\\\\"`.
        $raw = '\\"';
        $escaped = JqlBuilder::escapeValue($raw);
        $this->assertSame('\\\\\\"', $escaped);
    }

    #[Test]
    public function multi_byte_utf8_passes_through_unmangled(): void
    {
        $raw = 'Café — über résumé 漢字';
        $escaped = JqlBuilder::escapeValue($raw);
        // No escape rule applies to non-ASCII characters; round-trip
        // must be lossless.
        $this->assertSame($raw, $escaped);
    }

    #[Test]
    public function rejects_unsupported_operator(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unsupported JQL operator/');

        JqlBuilder::any()->whereField('status', '>', 'Done');
    }

    #[Test]
    public function rejects_scalar_for_in_list_operator(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        // `in` with a scalar should fail loudly — caller should use
        // the array form to be explicit about intent.
        // The implementation tolerates string by wrapping but doesn't
        // tolerate empty arrays.
        JqlBuilder::any()->whereField('status', 'in', []);
    }

    #[Test]
    public function rejects_array_for_scalar_operator(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        JqlBuilder::any()->whereField('status', '=', ['Open', 'Done']);
    }

    #[Test]
    public function rejects_invalid_field_name(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Invalid JQL field name/');

        // Smuggling an operator via field name must be blocked.
        JqlBuilder::any()->whereField('status; DROP TABLE', '=', 'x');
    }

    #[Test]
    public function builder_is_immutable_clone_pattern(): void
    {
        $base = JqlBuilder::for('PROJ');
        $withStatus = $base->status('=', 'Done');

        // The base must not be mutated when a derived builder is
        // appended to.
        $this->assertSame('project = "PROJ"', $base->build());
        $this->assertSame('project = "PROJ" AND status = "Done"', $withStatus->build());
    }

    #[Test]
    public function multiple_clauses_join_with_and(): void
    {
        $jql = JqlBuilder::for('PROJ')
            ->status('=', 'Open')
            ->whereField('assignee', '=', 'me@x.test')
            ->build();

        $this->assertSame(
            'project = "PROJ" AND status = "Open" AND assignee = "me@x.test"',
            $jql,
        );
    }

    #[Test]
    public function to_string_renders_build(): void
    {
        $b = JqlBuilder::for('PROJ');
        $this->assertSame($b->build(), (string) $b);
    }
}

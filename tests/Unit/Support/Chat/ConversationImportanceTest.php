<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Chat;

use App\Support\Chat\ConversationImportance;
use Tests\TestCase;

/**
 * Drift guard for the importance taxonomy.
 *
 * The stored value is a STRING, so ordering on the column directly is
 * alphabetical. `critical < high < normal` is accidentally almost
 * correct today, which is exactly why this needs pinning: the ordering
 * must come from the weights, and a fourth case must not be able to
 * appear without its weight.
 */
final class ConversationImportanceTest extends TestCase
{
    public function test_it_exposes_exactly_three_stable_machine_readable_values(): void
    {
        $this->assertSame(
            ['normal', 'high', 'critical'],
            array_map(
                static fn (ConversationImportance $c): string => $c->value,
                ConversationImportance::cases(),
            ),
        );
    }

    public function test_critical_sorts_before_high_which_sorts_before_normal(): void
    {
        $this->assertLessThan(
            ConversationImportance::High->weight(),
            ConversationImportance::Critical->weight(),
        );
        $this->assertLessThan(
            ConversationImportance::Normal->weight(),
            ConversationImportance::High->weight(),
        );
    }

    public function test_the_order_by_case_names_every_case_so_none_can_sort_last_silently(): void
    {
        $sql = ConversationImportance::orderByCaseSql();

        foreach (ConversationImportance::cases() as $case) {
            $this->assertStringContainsString(
                sprintf("WHEN '%s' THEN %d", $case->value, $case->weight()),
                $sql,
                sprintf('Case %s is missing from the ordering CASE.', $case->value),
            );
        }
        $this->assertStringStartsWith('CASE importance', $sql);
        $this->assertStringEndsWith('ELSE 99 END', $sql);
    }

    public function test_the_order_by_case_targets_the_requested_column(): void
    {
        $this->assertStringStartsWith(
            'CASE conversations.importance',
            ConversationImportance::orderByCaseSql('conversations.importance'),
        );
    }

    public function test_labels_are_localized_while_values_stay_english(): void
    {
        // R24: the machine-readable identifier never localizes.
        app()->setLocale('it');
        $this->assertSame('Critica', ConversationImportance::Critical->label());
        $this->assertSame('critical', ConversationImportance::Critical->value);

        app()->setLocale('en');
        $this->assertSame('Critical', ConversationImportance::Critical->label());
        $this->assertSame('critical', ConversationImportance::Critical->value);
    }
}

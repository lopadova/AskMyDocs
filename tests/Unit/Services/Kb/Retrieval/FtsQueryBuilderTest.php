<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Kb\Retrieval;

use App\Services\Kb\Retrieval\FtsQueryBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * v8.41/W1 (ADR 0035 §D3) — the lexical tsquery builder is pure, so the whole
 * syntax matrix runs on any driver (the full-text branch itself is pgsql-only).
 */
final class FtsQueryBuilderTest extends TestCase
{
    private FtsQueryBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new FtsQueryBuilder();
    }

    public function test_plain_syntax_is_byte_identical_to_the_legacy_builder(): void
    {
        [$expr, $bindings] = $this->builder->build('italian', 'cache invalidation', [], 'plain');

        $this->assertSame('plainto_tsquery(?, ?)', $expr);
        $this->assertSame(['italian', 'cache invalidation'], $bindings);
    }

    public function test_plain_syntax_or_expands_synonym_phrases_like_before(): void
    {
        [$expr, $bindings] = $this->builder->build('english', 'storno', ['credit note', 'refund'], 'plain');

        $this->assertSame('(plainto_tsquery(?, ?) || plainto_tsquery(?, ?) || plainto_tsquery(?, ?))', $expr);
        $this->assertSame(['english', 'storno', 'english', 'credit note', 'english', 'refund'], $bindings);
    }

    public function test_plain_is_the_default_syntax(): void
    {
        $this->assertSame(
            $this->builder->build('italian', 'x', [], 'plain'),
            $this->builder->build('italian', 'x'),
        );
    }

    public function test_websearch_uses_websearch_to_tsquery_for_a_multiword_query(): void
    {
        [$expr, $bindings] = $this->builder->build('english', 'refund policy', [], 'websearch');

        $this->assertSame('websearch_to_tsquery(?, ?)', $expr);
        $this->assertSame(['english', 'refund policy'], $bindings);
    }

    public function test_websearch_keeps_quotes_and_negation_for_postgres_to_interpret(): void
    {
        [$expr, $bindings] = $this->builder->build('english', '"credit note" -draft', [], 'websearch');

        $this->assertSame('websearch_to_tsquery(?, ?)', $expr);
        $this->assertSame('"credit note" -draft', $bindings[1]);
    }

    public function test_websearch_prefix_matches_a_bare_single_word(): void
    {
        [$expr, $bindings] = $this->builder->build('italian', 'rimbors', [], 'websearch');

        $this->assertSame('to_tsquery(?, ?)', $expr);
        $this->assertSame(['italian', 'rimbors:*'], $bindings);
    }

    #[DataProvider('identifiers')]
    public function test_websearch_matches_a_compound_identifier_as_a_phrase(string $token): void
    {
        [$expr, $bindings] = $this->builder->build('english', $token, [], 'websearch');

        $this->assertSame('phraseto_tsquery(?, ?)', $expr);
        $this->assertSame(['english', $token], $bindings);
    }

    /** @return array<string, array{string}> */
    public static function identifiers(): array
    {
        return [
            'ticket id' => ['DEC-0054'],
            'file path' => ['src/lib/i18n.ts'],
            'version' => ['2026.4.10'],
            'scoped package' => ['@tobilu/qmd'],
            'error code' => ['ERR_STOCK_0417'],
        ];
    }

    public function test_a_lone_negation_is_left_to_websearch_not_turned_into_a_prefix_match(): void
    {
        [$expr] = $this->builder->build('english', '-sandbox', [], 'websearch');

        $this->assertSame('websearch_to_tsquery(?, ?)', $expr);
    }

    /** SEC-RAWSQL-001: no tsquery operator can reach to_tsquery through the prefix path. */
    #[DataProvider('hostileTokens')]
    public function test_hostile_input_never_becomes_a_to_tsquery_operator(string $token): void
    {
        [$expr, $bindings] = $this->builder->build('english', $token, [], 'websearch');

        // Whatever builder path is chosen, user text is a BOUND parameter, never SQL.
        $this->assertStringNotContainsString($token, $expr);
        $this->assertSame(substr_count($expr, '?'), count($bindings));

        // If it took the to_tsquery(prefix) path the bound value must be operator-free.
        if (str_starts_with($expr, 'to_tsquery')) {
            $this->assertMatchesRegularExpression('/^[\p{L}\p{N}]+:\*$/u', $bindings[1]);
        }
    }

    /** @return array<string, array{string}> */
    public static function hostileTokens(): array
    {
        return [
            'tsquery and' => ['a&b'],
            'tsquery or' => ['a|b'],
            'tsquery not' => ['!a'],
            'parens' => ['(a)'],
            'prefix marker' => ['a:*'],
            'quote' => ["o'brien"],
            'sql comment' => ['x--'],
            'semicolon' => ['x;drop'],
        ];
    }

    public function test_every_phrase_including_synonyms_goes_through_the_same_classification(): void
    {
        [$expr, $bindings] = $this->builder->build('english', 'storno', ['credit note', 'REF-2291'], 'websearch');

        $this->assertSame('(to_tsquery(?, ?) || websearch_to_tsquery(?, ?) || phraseto_tsquery(?, ?))', $expr);
        $this->assertSame(['english', 'storno:*', 'english', 'credit note', 'english', 'REF-2291'], $bindings);
    }

    public function test_placeholder_count_always_matches_binding_count(): void
    {
        foreach (['plain', 'websearch'] as $syntax) {
            foreach (['one', 'two words', '"q" -x', 'A-1', ''] as $q) {
                [$expr, $bindings] = $this->builder->build('english', $q, ['syn one', 'syn'], $syntax);
                $this->assertSame(substr_count($expr, '?'), count($bindings), "syntax={$syntax} q=[{$q}]");
            }
        }
    }

    public function test_rank_function_is_bounded_only_for_websearch(): void
    {
        $this->assertSame('ts_rank', $this->builder->rankFunction('plain'));
        $this->assertSame('', $this->builder->rankNormalisationArg('plain'));
        $this->assertSame('ts_rank_cd', $this->builder->rankFunction('websearch'));
        $this->assertSame(', 32', $this->builder->rankNormalisationArg('websearch'));
    }
}

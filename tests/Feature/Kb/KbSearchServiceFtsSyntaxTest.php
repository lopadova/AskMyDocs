<?php

declare(strict_types=1);

namespace Tests\Feature\Kb;

use App\Services\Kb\KbSearchService;
use ReflectionMethod;
use Tests\TestCase;

/**
 * v8.41/W1 (ADR 0035 §D3) — `KB_FTS_QUERY_SYNTAX` selects the tsquery builder.
 * The full-text branch is pgsql-only (SQLite skips it), so the seam under test
 * is the private `buildFtsTsquery()` / `ftsSyntax()` pair via reflection — the
 * part W1 changes — in BOTH flag states (R43).
 */
final class KbSearchServiceFtsSyntaxTest extends TestCase
{
    /** @return array{0: string, 1: list<string>} */
    private function build(string $query): array
    {
        $service = app(KbSearchService::class);
        $method = new ReflectionMethod($service, 'buildFtsTsquery');
        $method->setAccessible(true);

        return $method->invoke($service, 'english', $query, null);
    }

    private function syntax(): string
    {
        $service = app(KbSearchService::class);
        $method = new ReflectionMethod($service, 'ftsSyntax');
        $method->setAccessible(true);

        return (string) $method->invoke($service);
    }

    public function test_default_syntax_is_plain_and_the_sql_is_the_legacy_one(): void
    {
        $this->assertSame('plain', config('kb.hybrid_search.query_syntax'));
        [$expr, $bindings] = $this->build('refund policy');

        $this->assertSame('plainto_tsquery(?, ?)', $expr);
        $this->assertSame(['english', 'refund policy'], $bindings);
    }

    public function test_websearch_syntax_switches_the_builder(): void
    {
        config()->set('kb.hybrid_search.query_syntax', 'websearch');

        [$expr] = $this->build('"credit note" -draft');
        $this->assertSame('websearch_to_tsquery(?, ?)', $expr);

        [$expr, $bindings] = $this->build('DEC-0054');
        $this->assertSame('phraseto_tsquery(?, ?)', $expr);
        $this->assertSame(['english', 'DEC-0054'], $bindings);
    }

    public function test_an_unknown_configured_value_degrades_to_plain_not_to_an_error(): void
    {
        config()->set('kb.hybrid_search.query_syntax', 'bogus');

        $this->assertSame('plain', $this->syntax());
        [$expr] = $this->build('anything');
        $this->assertSame('plainto_tsquery(?, ?)', $expr);
    }
}

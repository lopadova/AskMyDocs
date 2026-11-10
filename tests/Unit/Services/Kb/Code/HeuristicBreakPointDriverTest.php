<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Kb\Code;

use App\Services\Kb\Code\BreakPoint;
use App\Services\Kb\Code\HeuristicBreakPointDriver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HeuristicBreakPointDriverTest extends TestCase
{
    /**
     * @param  list<BreakPoint>  $points
     * @return array<int, BreakPoint>
     */
    private function byLine(array $points): array
    {
        $map = [];
        foreach ($points as $p) {
            $map[$p->line] = $p;
        }

        return $map;
    }

    #[Test]
    public function php_class_and_methods_get_structural_scores_and_symbol_paths(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../Fixtures/code/Invoice.php');
        $points = $this->byLine((new HeuristicBreakPointDriver())->breakPoints((string) $source, 'php'));

        $imports = array_values(array_filter($points, static fn (BreakPoint $p): bool => $p->type === 'imports'));
        $this->assertNotEmpty($imports);
        $this->assertSame(60, $imports[0]->score);

        $classes = array_values(array_filter($points, static fn (BreakPoint $p): bool => $p->type === 'class'));
        $this->assertCount(1, $classes);
        $this->assertSame('Invoice', $classes[0]->symbol);
        $this->assertSame(100, $classes[0]->score);
        // The doc comment is attached to the class, not left behind.
        $this->assertSame(10, $classes[0]->line);

        $methods = array_values(array_filter($points, static fn (BreakPoint $p): bool => $p->type === 'method'));
        $this->assertSame(['Invoice > __construct', 'Invoice > subtotal', 'Invoice > tax', 'Invoice > total'], array_map(static fn (BreakPoint $p): ?string => $p->symbol, $methods));
        $this->assertSame([80], array_values(array_unique(array_map(static fn (BreakPoint $p): int => $p->score, $methods))));

        $functions = array_values(array_filter($points, static fn (BreakPoint $p): bool => $p->type === 'function'));
        $this->assertSame('format_money', $functions[0]->symbol);
        $this->assertSame(90, $functions[0]->score);
    }

    #[Test]
    public function end_lines_follow_braces_for_brace_languages(): void
    {
        $source = "class A {\n  function f() {\n    return 1;\n  }\n}\n";
        $points = (new HeuristicBreakPointDriver())->breakPoints($source, 'php');

        $class = array_values(array_filter($points, static fn (BreakPoint $p): bool => $p->type === 'class'))[0];
        $this->assertSame(1, $class->line);
        $this->assertSame(5, $class->endLine);
    }

    #[Test]
    public function python_uses_indentation_and_nests_methods_under_the_class(): void
    {
        $source = "import os\n\n@decorator\nclass Repo:\n    def find(self):\n        return 1\n\n    def save(self):\n        pass\n\ndef main():\n    pass\n";
        $points = (new HeuristicBreakPointDriver())->breakPoints($source, 'python');
        $symbols = array_map(static fn (BreakPoint $p): ?string => $p->symbol, array_filter($points, static fn (BreakPoint $p): bool => $p->isDeclaration()));

        $this->assertSame(['Repo', 'Repo > find', 'Repo > save', 'main'], array_values($symbols));
        $class = array_values(array_filter($points, static fn (BreakPoint $p): bool => $p->type === 'class'))[0];
        $this->assertSame(3, $class->line, 'the decorator belongs to the class');
        $this->assertSame(9, $class->endLine);
    }

    #[Test]
    public function typescript_exports_arrow_functions_and_types(): void
    {
        $source = "import { a } from 'x';\n\nexport class Foo {\n  bar() {}\n}\nexport const helper = (n: number) => n + 1;\nexport function top() {}\nexport type Id = string;\n";
        $symbols = array_values(array_map(
            static fn (BreakPoint $p): ?string => $p->symbol,
            array_filter((new HeuristicBreakPointDriver())->breakPoints($source, 'typescript'), static fn (BreakPoint $p): bool => $p->isDeclaration()),
        ));

        $this->assertContains('Foo', $symbols);
        $this->assertContains('helper', $symbols);
        $this->assertContains('top', $symbols);
        $this->assertContains('Id', $symbols);
    }

    #[Test]
    public function tsx_uses_the_typescript_patterns(): void
    {
        $symbols = array_map(
            static fn (BreakPoint $p): ?string => $p->symbol,
            (new HeuristicBreakPointDriver())->breakPoints("import React from 'react';\n\nexport function App() {\n  return <div/>;\n}\n", 'tsx'),
        );

        $this->assertContains('App', $symbols);
    }

    #[Test]
    public function go_rust_java_csharp_ruby_bash_sql_yaml_json_each_find_their_declarations(): void
    {
        $driver = new HeuristicBreakPointDriver();
        $cases = [
            'go' => ["package main\n\nfunc (s *Svc) Run() error {\n  return nil\n}\n\nfunc main() {}\n", 'Run'],
            'rust' => ["use std::fmt;\n\npub struct Wallet {}\n\nimpl Wallet {\n    pub fn balance(&self) -> u64 { 0 }\n}\n", 'Wallet'],
            'java' => ["package a;\n\npublic class Order {\n    public int total() {\n        return 1;\n    }\n}\n", 'Order'],
            'csharp' => ["using System;\n\npublic class Order\n{\n    public int Total()\n    {\n        return 1;\n    }\n}\n", 'Order'],
            'ruby' => ["require 'json'\n\nclass Order\n  def total\n    1\n  end\nend\n", 'Order'],
            'bash' => ["#!/bin/bash\n\nbackup() {\n  echo hi\n}\n", 'backup'],
            'sql' => ["CREATE TABLE invoices (\n  id int\n);\n\nCREATE OR REPLACE FUNCTION total() RETURNS int AS \$\$ SELECT 1 \$\$ LANGUAGE sql;\n", 'invoices'],
            'yaml' => ["services:\n  web:\n    image: nginx\n\nvolumes:\n  data: {}\n", 'services'],
            'json' => ["{\n  \"name\": \"x\",\n  \"scripts\": {\n    \"a\": \"b\"\n  }\n}\n", 'scripts'],
        ];
        foreach ($cases as $language => [$source, $expected]) {
            $symbols = array_map(static fn (BreakPoint $p): ?string => $p->symbol, $driver->breakPoints($source, $language));
            $this->assertNotEmpty(array_filter($symbols, static fn (?string $s): bool => $s !== null && str_contains($s, $expected)), "{$language}: expected a symbol containing {$expected}, got ".json_encode($symbols));
        }
    }

    #[Test]
    public function unknown_language_and_empty_source_yield_no_points(): void
    {
        $driver = new HeuristicBreakPointDriver();

        $this->assertSame([], $driver->breakPoints('whatever', 'cobol'));
        $this->assertSame([], $driver->breakPoints('', 'php'));
    }

    #[Test]
    public function output_is_sorted_and_one_point_per_line(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../Fixtures/code/Invoice.php');
        $points = (new HeuristicBreakPointDriver())->breakPoints((string) $source, 'php');
        $lines = array_map(static fn (BreakPoint $p): int => $p->line, $points);
        $sorted = $lines;
        sort($sorted);

        $this->assertSame($sorted, $lines);
        $this->assertSame($lines, array_values(array_unique($lines)));
    }
}

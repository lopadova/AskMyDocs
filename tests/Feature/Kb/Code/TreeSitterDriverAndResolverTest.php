<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\Code;

use App\Services\Kb\Code\BreakPointDriverResolver;
use App\Services\Kb\Code\CodeChunkerUnavailableException;
use App\Services\Kb\Code\TreeSitterBreakPointDriver;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * ADR 0035 D7 degradation ladder, proved with FAKE helper scripts (so the
 * crash / timeout / garbage scenarios are deterministic and need no
 * `npm install`) plus one test against the REAL helper that skips when its
 * dependencies are not installed.
 */
final class TreeSitterDriverAndResolverTest extends TestCase
{
    /** @var list<string> */
    private array $tmp = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (! $this->nodeAvailable()) {
            $this->markTestSkipped('node is not installed on this machine.');
        }
        config()->set('kb.code.probe_ttl', 0);
        config()->set('kb.code.parse_timeout', 2);
        TreeSitterBreakPointDriver::forgetProbe();
    }

    protected function tearDown(): void
    {
        TreeSitterBreakPointDriver::forgetProbe();
        foreach ($this->tmp as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    private function nodeAvailable(): bool
    {
        $p = new Process(['node', '--version']);
        try {
            $p->run();
        } catch (\Throwable) {
            return false;
        }

        return $p->isSuccessful();
    }

    /** Write a fake helper: `--probe` answers $probe, `--language` runs $parse. */
    private function fakeHelper(string $probeJson, string $parseJs): string
    {
        $path = sys_get_temp_dir().'/fake-code-chunker-'.bin2hex(random_bytes(4)).'.mjs';
        file_put_contents($path, <<<JS
        const args = process.argv.slice(2);
        if (args.includes('--probe')) { process.stdout.write({$this->js($probeJson)} + "\\n"); process.exit(0); }
        for await (const _ of process.stdin) {}
        {$parseJs}
        JS);
        $this->tmp[] = $path;
        config()->set('kb.code.helper_path', $path);

        return $path;
    }

    private function js(string $s): string
    {
        return json_encode($s, JSON_THROW_ON_ERROR);
    }

    private const PROBE_OK = '{"ok":true,"helper":1,"grammars":["php","python"]}';

    private function resolver(): BreakPointDriverResolver
    {
        return $this->app->make(BreakPointDriverResolver::class);
    }

    #[Test]
    public function a_well_behaved_helper_yields_tree_sitter_break_points(): void
    {
        $this->fakeHelper(self::PROBE_OK, 'process.stdout.write(JSON.stringify({ok:true,language:"php",breakpoints:[{line:3,end_line:9,score:100,type:"class",symbol:"A",depth:0},{line:1,end_line:1,score:60,type:"imports",symbol:null,depth:0}]})+"\n");');
        config()->set('kb.code.chunker_driver', 'auto');

        $result = $this->resolver()->breakPoints("<?php\n", 'php');

        $this->assertSame('tree_sitter', $result['driver']);
        $this->assertSame([1, 3], array_map(static fn ($p): int => $p->line, $result['points']), 'sorted ascending');
        $this->assertSame('A', $result['points'][1]->symbol);
    }

    #[Test]
    public function auto_falls_back_to_the_heuristic_on_timeout_crash_and_garbage_without_leaking_source(): void
    {
        $scenarios = [
            'timeout' => 'await new Promise((r) => setTimeout(r, 30000));',
            'crash' => 'process.exit(4);',
            'garbage' => 'process.stdout.write("not json at all\n");',
            'wrong shape' => 'process.stdout.write(JSON.stringify({ok:true,breakpoints:[{nope:1}]})+"\n");',
            'oversize output' => 'process.stdout.write(JSON.stringify({ok:true,breakpoints:[],pad:"x".repeat(2000000)})+"\n");',
        ];
        config()->set('kb.code.chunker_driver', 'auto');
        config()->set('kb.code.max_helper_output_bytes', 1024);
        $secretSource = "<?php\n\$k = 'TOP-SECRET-MARKER';\nclass A {}\n";

        foreach ($scenarios as $name => $js) {
            TreeSitterBreakPointDriver::forgetProbe();
            $this->fakeHelper(self::PROBE_OK, $js);
            Log::spy();

            $result = $this->resolver()->breakPoints($secretSource, 'php');

            $this->assertSame('heuristic', $result['driver'], "{$name}: must degrade to the heuristic driver");
            $this->assertNotEmpty($result['points'], "{$name}: the heuristic still finds the class");
            Log::shouldHaveReceived('warning')->withArgs(static function (string $message, array $context = []): bool {
                // Reason code + language only: never source text.
                return isset($context['reason'], $context['language']) && ! str_contains(json_encode($context), 'TOP-SECRET-MARKER');
            })->atLeast()->once();
        }
    }

    #[Test]
    public function forced_tree_sitter_fails_loudly_instead_of_degrading(): void
    {
        config()->set('kb.code.chunker_driver', 'tree_sitter');

        foreach (['timeout' => 'await new Promise((r) => setTimeout(r, 30000));', 'crash' => 'process.exit(4);', 'garbage' => 'process.stdout.write("nope\n");'] as $name => $js) {
            TreeSitterBreakPointDriver::forgetProbe();
            $this->fakeHelper(self::PROBE_OK, $js);
            try {
                $this->resolver()->breakPoints("<?php\nclass A {}\n", 'php');
                $this->fail("{$name}: forced tree_sitter must throw");
            } catch (CodeChunkerUnavailableException $e) {
                $this->assertNotSame('', $e->reason);
                $this->assertStringNotContainsString('class A', $e->getMessage());
            }
        }
    }

    #[Test]
    public function a_missing_helper_or_node_is_unavailable_in_forced_mode_and_a_heuristic_fallback_in_auto(): void
    {
        config()->set('kb.code.helper_path', sys_get_temp_dir().'/does-not-exist-'.bin2hex(random_bytes(3)).'.mjs');

        config()->set('kb.code.chunker_driver', 'auto');
        $this->assertSame('heuristic', $this->resolver()->breakPoints("<?php\nclass A {}\n", 'php')['driver']);

        TreeSitterBreakPointDriver::forgetProbe();
        config()->set('kb.code.chunker_driver', 'tree_sitter');
        $this->expectException(CodeChunkerUnavailableException::class);
        $this->resolver()->breakPoints("<?php\nclass A {}\n", 'php');
    }

    #[Test]
    public function a_language_without_a_grammar_always_uses_the_heuristic_even_when_forced(): void
    {
        $this->fakeHelper(self::PROBE_OK, 'process.exit(4);');
        config()->set('kb.code.chunker_driver', 'tree_sitter');

        $this->assertSame('heuristic', $this->resolver()->breakPoints("CREATE TABLE t (id int);\n", 'sql')['driver']);
        $this->assertSame('heuristic', $this->resolver()->breakPoints("a: 1\n", 'yaml')['driver']);
    }

    #[Test]
    public function a_grammar_missing_from_the_probe_is_unavailable_for_that_language_only(): void
    {
        $this->fakeHelper(self::PROBE_OK, 'process.stdout.write(JSON.stringify({ok:true,breakpoints:[]})+"\n");');
        config()->set('kb.code.chunker_driver', 'auto');

        $this->assertSame('tree_sitter', $this->resolver()->breakPoints("x = 1\n", 'python')['driver']);
        $this->assertSame('heuristic', $this->resolver()->breakPoints("package main\n", 'go')['driver']);
    }

    #[Test]
    public function heuristic_mode_and_unknown_modes_never_spawn_a_process(): void
    {
        $marker = sys_get_temp_dir().'/spawned-'.bin2hex(random_bytes(4));
        $this->tmp[] = $marker;
        $this->fakeHelper(self::PROBE_OK, 'process.exit(0);');
        // A helper that would leave a trace if it were ever executed.
        file_put_contents((string) config('kb.code.helper_path'), 'import { writeFileSync } from "node:fs"; writeFileSync('.json_encode($marker).', "x"); console.log(JSON.stringify({ok:true,helper:1,grammars:["php"]}));');

        foreach (['heuristic', 'bogus-mode', ''] as $mode) {
            config()->set('kb.code.chunker_driver', $mode);
            $this->assertSame('heuristic', $this->resolver()->breakPoints("<?php\nclass A {}\n", 'php')['driver'], "mode [{$mode}]");
        }
        $this->assertFileDoesNotExist($marker, 'heuristic/unknown modes must not execute the helper');
    }

    #[Test]
    public function the_real_helper_parses_php_when_its_dependencies_are_installed(): void
    {
        config()->set('kb.code.helper_path', dirname(__DIR__, 4).'/tools/code-chunker/index.mjs');
        TreeSitterBreakPointDriver::forgetProbe();
        $driver = $this->app->make(TreeSitterBreakPointDriver::class);
        $probe = $driver->probe(true);
        if (! $probe['ok']) {
            $this->markTestSkipped('tools/code-chunker dependencies are not installed ('.$probe['reason'].'). Run `npm install` there.');
        }

        $source = (string) file_get_contents(__DIR__.'/../../../Fixtures/code/Invoice.php');
        $points = $driver->breakPoints($source, 'php');
        $symbols = array_map(static fn ($p): ?string => $p->symbol, array_filter($points, static fn ($p): bool => $p->isDeclaration()));

        $this->assertContains('Invoice', $symbols);
        $this->assertContains('Invoice > total', $symbols);
        $this->assertContains('format_money', $symbols);
        $class = array_values(array_filter($points, static fn ($p): bool => $p->type === 'class'))[0];
        $this->assertSame(10, $class->line, 'doc comment is attached to the class');
        $this->assertSame(100, $class->score);
    }

    #[Test]
    public function the_real_helper_rejects_an_unknown_language_with_a_documented_exit_code(): void
    {
        $helper = dirname(__DIR__, 4).'/tools/code-chunker/index.mjs';
        config()->set('kb.code.helper_path', $helper);
        if (! $this->app->make(TreeSitterBreakPointDriver::class)->probe(true)['ok']) {
            $this->markTestSkipped('tools/code-chunker dependencies are not installed.');
        }
        $p = new Process(['node', $helper, '--language', 'cobol'], dirname($helper));
        $p->setInput('IDENTIFICATION DIVISION.');
        $p->run();

        $this->assertSame(3, $p->getExitCode());
        $this->assertStringNotContainsString('IDENTIFICATION', $p->getOutput(), 'the helper never echoes source');
    }
}

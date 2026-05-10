<?php

declare(strict_types=1);

namespace Tests\Feature\Eval;

use App\Eval\Support\EvalHarnessRunner;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Feature coverage for `eval:nightly` (v4.3/W3).
 *
 * Each test name maps 1:1 to the assertion in its body (R16):
 *   - dry-run path takes no action;
 *   - live-mode refusal fires when EVAL_NIGHTLY_LIVE=true but no
 *     provider key is configured;
 *   - the report is written to the dated nightly path;
 *   - regression beyond threshold fires Log::alert AND writes the
 *     sidecar JSON;
 *   - within-threshold runs do NOT fire the alert;
 *   - the first run (no prior baseline) succeeds without alerting.
 *
 * R26 — every non-dry-run test mocks the underlying `eval-harness:run`
 * Artisan call so no real RAG pipeline executes; the tests then
 * inject the report file the package would have written. This keeps
 * the suite hermetic AND fast.
 */
final class EvalNightlyCommandTest extends TestCase
{
    private string $diskName = 'eval-nightly-test';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake($this->diskName);
        config()->set('eval-harness.reports.disk', $this->diskName);
        config()->set('eval-harness.askmydocs.live_ai', false);

        // Default: no live-AI override, no provider keys. Individual
        // tests opt in via setEnv() when they need to exercise the
        // live-mode refusal path. Use the Env repository (NOT bare
        // putenv) so Laravel's cached env() values get refreshed.
        $this->setEnv('EVAL_NIGHTLY_LIVE', 'false');
        $this->setEnv('EVAL_NIGHTLY_REGRESSION_THRESHOLD', '0.05');
        $this->setEnv('EVAL_NIGHTLY_RETENTION_DAYS', '90');

        Carbon::setTestNow(Carbon::parse('2026-05-10 05:30:00'));

        // R26 — block any stray HTTP that would slip past the harness.
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->clearEnv('EVAL_NIGHTLY_LIVE');
        $this->clearEnv('EVAL_NIGHTLY_REGRESSION_THRESHOLD');
        $this->clearEnv('EVAL_NIGHTLY_RETENTION_DAYS');
        $this->clearEnv('EVAL_LIVE_AI');

        parent::tearDown();
    }

    private function setEnv(string $name, string $value): void
    {
        // Mutate every adapter Laravel's Env::get() consults so the
        // value resolves uniformly regardless of resolution order.
        // Repository::set alone does NOT touch $_SERVER, which
        // phpunit.xml seeds with a per-suite OPENAI_API_KEY etc.
        \Illuminate\Support\Env::getRepository()->set($name, $value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
        putenv($name.'='.$value);
    }

    private function clearEnv(string $name): void
    {
        \Illuminate\Support\Env::getRepository()->clear($name);
        unset($_ENV[$name], $_SERVER[$name]);
        putenv($name);
    }

    public function test_dry_run_does_not_execute_real_run(): void
    {
        $this->bindRunnerThatMustNotRun();

        $this->artisan('eval:nightly', ['--dry-run' => true])
            ->expectsOutputToContain('eval:nightly dry-run')
            ->assertSuccessful();

        Storage::disk($this->diskName)->assertDirectoryEmpty('eval-harness/nightly');
    }

    public function test_command_refuses_when_live_requested_without_provider_key(): void
    {
        // Force the "operator wanted live but no key" arm by setting the
        // override true while every provider env var is empty.
        $this->setEnv('EVAL_NIGHTLY_LIVE', 'true');
        $this->clearProviderKeys();

        Log::shouldReceive('alert')
            ->once()
            ->withArgs(function (string $message, array $context = []): bool {
                return str_contains($message, 'refused live run')
                    && ($context['date'] ?? null) === '2026-05-10';
            });
        Log::shouldReceive('warning')->andReturnNull();

        $this->bindRunnerThatMustNotRun();

        $this->artisan('eval:nightly')
            ->expectsOutputToContain('Live mode requested but no provider key found.')
            ->assertSuccessful();
    }

    public function test_command_writes_report_to_nightly_path(): void
    {
        $this->stubArtisanRun(macroF1: 0.91);

        $this->artisan('eval:nightly')->assertSuccessful();

        Storage::disk($this->diskName)->assertExists('eval-harness/nightly/2026-05-10.json');
        Storage::disk($this->diskName)->assertExists('eval-harness/nightly/2026-05-10.md');
    }

    public function test_command_alerts_when_regression_exceeds_threshold(): void
    {
        Storage::disk($this->diskName)->put(
            'eval-harness/nightly/2026-05-09.json',
            $this->reportPayload(macroF1: 0.92, contains: 0.9),
        );

        $this->stubArtisanRun(macroF1: 0.80, contains: 0.7);

        Log::shouldReceive('alert')
            ->atLeast()
            ->once()
            ->withArgs(function (string $message): bool {
                return str_contains($message, 'REGRESSION detected');
            });

        $this->artisan('eval:nightly')->assertSuccessful();

        Storage::disk($this->diskName)->assertExists('eval-harness/nightly/2026-05-10.alert.json');

        $sidecar = json_decode(
            Storage::disk($this->diskName)->get('eval-harness/nightly/2026-05-10.alert.json'),
            true,
        );
        $this->assertSame('2026-05-10', $sidecar['date']);
        $this->assertEqualsWithDelta(0.05, $sidecar['threshold'], 1e-9);
        $this->assertLessThan(-0.05, $sidecar['delta']['macro_f1_delta']);
    }

    public function test_command_does_not_alert_when_within_threshold(): void
    {
        Storage::disk($this->diskName)->put(
            'eval-harness/nightly/2026-05-09.json',
            $this->reportPayload(macroF1: 0.92, contains: 0.9),
        );

        $this->stubArtisanRun(macroF1: 0.90, contains: 0.88);

        Log::shouldReceive('alert')->never();

        $this->artisan('eval:nightly')->assertSuccessful();

        Storage::disk($this->diskName)->assertMissing('eval-harness/nightly/2026-05-10.alert.json');
    }

    public function test_first_run_with_no_prior_report_succeeds(): void
    {
        $this->stubArtisanRun(macroF1: 0.90);

        Log::shouldReceive('alert')->never();

        $this->artisan('eval:nightly')
            ->expectsOutputToContain('first run')
            ->assertSuccessful();

        Storage::disk($this->diskName)->assertExists('eval-harness/nightly/2026-05-10.json');
        Storage::disk($this->diskName)->assertMissing('eval-harness/nightly/2026-05-10.alert.json');
    }

    public function test_command_runs_in_fake_mode_when_eval_live_ai_set_but_nightly_live_false(): void
    {
        // Simulate a host with EVAL_LIVE_AI=1 in env (e.g. a developer's
        // dotfile leak) AND EVAL_NIGHTLY_LIVE=false. Without the
        // unconditional `Config::set('eval-harness.askmydocs.live_ai',
        // $live)` write inside invokeEvalRun(), the prior config value
        // would persist and the cron would silently bill tokens.
        $this->setEnv('EVAL_LIVE_AI', '1');
        $this->setEnv('EVAL_NIGHTLY_LIVE', 'false');

        // Pre-seed the config to live=true so the test fails loudly if
        // the command does NOT explicitly overwrite it back to false.
        config()->set('eval-harness.askmydocs.live_ai', true);

        $observedLiveAi = null;
        $disk = Storage::disk($this->diskName);
        $disk->makeDirectory('eval-harness/nightly');
        $payload = $this->reportPayload(macroF1: 0.90);
        $testCase = $this;

        $stub = new class($payload, $observedLiveAi, $testCase) extends EvalHarnessRunner
        {
            public ?bool $capturedLiveAi = null;

            public function __construct(
                private string $payload,
                private $unused,
                private $testCase,
            ) {}

            public function run(array $parameters): int
            {
                // Capture what config sees AT runner-call time. This is
                // the value the EvalRegistrar would consult to decide
                // whether to bind Http::fake() — the load-bearing fence.
                $this->capturedLiveAi = (bool) config('eval-harness.askmydocs.live_ai');

                $body = ($parameters['--json'] ?? false) === true
                    ? $this->payload
                    : '# stub';
                file_put_contents((string) $parameters['--out'], $body);

                return 0;
            }
        };

        $this->app->instance(EvalHarnessRunner::class, $stub);

        // R26 — no real provider HTTP call may leak through.
        Http::assertNothingSent();

        $this->artisan('eval:nightly')->assertSuccessful();

        $this->assertSame(
            false,
            $stub->capturedLiveAi,
            'EVAL_NIGHTLY_LIVE=false MUST force live_ai=false even when EVAL_LIVE_AI=1 is set in env.',
        );
        // R26 confirmation: still nothing sent after the run.
        Http::assertNothingSent();
    }

    private function stubArtisanRun(float $macroF1, float $contains = 0.85): void
    {
        $disk = Storage::disk($this->diskName);
        $disk->makeDirectory('eval-harness/nightly');

        $payload = $this->reportPayload($macroF1, $contains);
        $testCase = $this;

        $stub = new class($disk, $payload, $macroF1, $testCase) extends EvalHarnessRunner
        {
            public function __construct(
                private $disk,
                private string $payload,
                private float $macroF1,
                private $testCase,
            ) {
                // Skip parent constructor — no kernel needed for the stub.
            }

            public function run(array $parameters): int
            {
                $out = (string) ($parameters['--out'] ?? '');
                $this->testCase::assertNotSame('', $out);
                $this->testCase::assertTrue(
                    ($parameters['--raw-path'] ?? false) === true,
                    'eval:nightly must pass --raw-path so the absolute --out is honored verbatim.',
                );
                // Normalise Windows backslashes for the contains-check.
                $normalised = str_replace('\\', '/', $out);
                $this->testCase::assertStringContainsString('eval-harness/nightly/2026-05-10', $normalised);

                $body = ($parameters['--json'] ?? false) === true
                    ? $this->payload
                    : sprintf('# Eval report%smacro_f1: %.4f', PHP_EOL, $this->macroF1);
                // The command passes an ABSOLUTE path now (--raw-path).
                // Write to it directly so the disk lookup against the
                // original RELATIVE path (handle() keeps it for the
                // post-run readJsonReport call) still finds the file.
                file_put_contents($out, $body);

                return 0;
            }
        };

        $this->app->instance(EvalHarnessRunner::class, $stub);
    }

    private function bindRunnerThatMustNotRun(): void
    {
        $stub = new class extends EvalHarnessRunner
        {
            public function __construct() {}

            public function run(array $parameters): int
            {
                throw new \RuntimeException('EvalHarnessRunner::run was called; this test asserts it must NOT run.');
            }
        };

        $this->app->instance(EvalHarnessRunner::class, $stub);
    }

    private function reportPayload(float $macroF1, float $contains = 0.85): string
    {
        return json_encode([
            'schema_version' => 'eval-harness.report.v1',
            'dataset' => 'rag.askmydocs.factuality.fy2026',
            'macro_f1' => $macroF1,
            'total_samples' => 40,
            'total_failures' => 0,
            'metrics' => [
                'contains' => [
                    'mean' => $contains,
                    'p50' => $contains,
                    'p95' => $contains,
                    'pass_rate' => $contains,
                ],
                'cosine-embedding' => [
                    'mean' => $macroF1,
                    'p50' => $macroF1,
                    'p95' => $macroF1,
                    'pass_rate' => $macroF1,
                ],
            ],
        ], JSON_PRETTY_PRINT);
    }

    private function clearProviderKeys(): void
    {
        foreach ([
            'OPENAI_API_KEY',
            'OPENROUTER_API_KEY',
            'ANTHROPIC_API_KEY',
            'REGOLO_API_KEY',
            'EVAL_HARNESS_JUDGE_API_KEY',
            'EVAL_HARNESS_EMBEDDINGS_API_KEY',
        ] as $key) {
            $this->clearEnv($key);
        }
    }
}

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
        $this->clearEnv('EVAL_NIGHTLY_ADVERSARIAL');
        $this->clearEnv('EVAL_NIGHTLY_ADVERSARIAL_DATASETS');

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

    public function test_adversarial_nightly_disabled_by_default_runs_baseline_only(): void
    {
        // Default state — EVAL_NIGHTLY_ADVERSARIAL unset/false. The
        // baseline path must remain bit-identical to the v4.3/W3
        // behaviour: exactly two runner invocations (baseline JSON +
        // baseline MD), zero adversarial files, and the
        // adversarial_nightly.enabled config remains false.
        config()->set('eval-harness.adversarial_nightly.enabled', false);

        $stub = $this->bindRecordingRunner();

        $this->artisan('eval:nightly')->assertSuccessful();

        $this->assertSame(
            2,
            $stub->callCount,
            'Baseline-only path MUST invoke the runner exactly twice (JSON + MD).',
        );

        $disk = Storage::disk($this->diskName);
        $disk->assertExists('eval-harness/nightly/2026-05-10.json');
        $disk->assertExists('eval-harness/nightly/2026-05-10.md');

        // No adversarial artefacts at all.
        foreach ($disk->files('eval-harness/nightly') as $path) {
            $this->assertStringNotContainsString(
                '.adversarial.',
                $path,
                'Adversarial artefacts MUST NOT be written when EVAL_NIGHTLY_ADVERSARIAL is false.',
            );
        }
    }

    public function test_adversarial_nightly_enabled_runs_all_configured_datasets(): void
    {
        // Enable the opt-in master gate, no allowlist → every
        // configured adversarial dataset must run after the baseline.
        config()->set('eval-harness.adversarial_nightly.enabled', true);
        config()->set('eval-harness.adversarial_nightly.datasets', '');
        $configured = array_keys((array) config('eval-harness.askmydocs.golden.adversarial', []));
        $this->assertNotEmpty(
            $configured,
            'Test fixture invariant: at least one adversarial dataset must be configured.',
        );

        $stub = $this->bindRecordingRunner();

        $this->artisan('eval:nightly')->assertSuccessful();

        // 2 baseline + 2 per adversarial slug.
        $this->assertSame(
            2 + (2 * count($configured)),
            $stub->callCount,
            'Adversarial pass must run JSON+MD for each configured slug.',
        );

        $disk = Storage::disk($this->diskName);
        foreach ($configured as $slug) {
            $disk->assertExists("eval-harness/nightly/2026-05-10.adversarial.{$slug}.json");
            $disk->assertExists("eval-harness/nightly/2026-05-10.adversarial.{$slug}.md");
            $disk->assertExists("eval-harness/nightly/2026-05-10.adversarial.{$slug}.summary.json");
        }
    }

    public function test_adversarial_nightly_allowlist_filters_datasets(): void
    {
        config()->set('eval-harness.adversarial_nightly.enabled', true);
        config()->set('eval-harness.adversarial_nightly.datasets', 'out-of-corpus');

        $stub = $this->bindRecordingRunner();

        $this->artisan('eval:nightly')->assertSuccessful();

        // 2 baseline + 2 for the single allowlisted slug.
        $this->assertSame(4, $stub->callCount);

        $disk = Storage::disk($this->diskName);
        $disk->assertExists('eval-harness/nightly/2026-05-10.adversarial.out-of-corpus.summary.json');
        $disk->assertMissing('eval-harness/nightly/2026-05-10.adversarial.contradicting-claims.summary.json');
        $disk->assertMissing('eval-harness/nightly/2026-05-10.adversarial.rejected-approach-trigger.summary.json');
    }

    public function test_adversarial_nightly_unknown_slug_logs_warning_and_skips(): void
    {
        config()->set('eval-harness.adversarial_nightly.enabled', true);
        config()->set('eval-harness.adversarial_nightly.datasets', 'out-of-corpus,bogus-slug');

        $warningCaptured = false;
        Log::shouldReceive('warning')
            ->atLeast()
            ->once()
            ->withArgs(function (string $message, array $context = []) use (&$warningCaptured): bool {
                if (str_contains($message, 'adversarial slug not configured')
                    && ($context['slug'] ?? null) === 'bogus-slug') {
                    $warningCaptured = true;
                }

                return true;
            });
        Log::shouldReceive('alert')->never();

        $stub = $this->bindRecordingRunner();

        $this->artisan('eval:nightly')->assertSuccessful();

        $this->assertTrue(
            $warningCaptured,
            'Log::warning MUST fire with the unknown adversarial slug.',
        );

        // 2 baseline + 2 for the known slug; bogus-slug must be skipped.
        $this->assertSame(4, $stub->callCount);

        $disk = Storage::disk($this->diskName);
        $disk->assertExists('eval-harness/nightly/2026-05-10.adversarial.out-of-corpus.summary.json');
        $disk->assertMissing('eval-harness/nightly/2026-05-10.adversarial.bogus-slug.summary.json');
    }

    public function test_adversarial_nightly_runner_failure_does_not_block_other_datasets(): void
    {
        config()->set('eval-harness.adversarial_nightly.enabled', true);
        // Pin an explicit, ordered allowlist so the failure-injection
        // logic is deterministic regardless of array_keys() order.
        config()->set(
            'eval-harness.adversarial_nightly.datasets',
            'out-of-corpus,contradicting-claims,rejected-approach-trigger',
        );

        $disk = Storage::disk($this->diskName);
        $disk->makeDirectory('eval-harness/nightly');
        $payload = $this->reportPayload(macroF1: 0.90);
        $testCase = $this;

        $stub = new class($payload, $testCase) extends EvalHarnessRunner
        {
            public int $callCount = 0;

            public array $datasetsRun = [];

            public function __construct(
                private string $payload,
                private $testCase,
            ) {}

            public function run(array $parameters): int
            {
                $this->callCount++;
                $dataset = (string) ($parameters['dataset'] ?? '');
                $this->datasetsRun[] = $dataset;

                // Inject failure ONLY on the second adversarial slug
                // (contradicting-claims). Baseline + first slug + third
                // slug must all complete normally.
                if ($dataset === 'rag.askmydocs.adversarial.contradicting-claims') {
                    throw new \RuntimeException('Simulated provider failure for contradicting-claims.');
                }

                $body = ($parameters['--json'] ?? false) === true
                    ? $this->payload
                    : '# stub';
                file_put_contents((string) $parameters['--out'], $body);

                return 0;
            }
        };

        $warningSlugs = [];
        Log::shouldReceive('warning')
            ->atLeast()
            ->once()
            ->withArgs(function (string $message, array $context = []) use (&$warningSlugs): bool {
                if (str_contains($message, 'adversarial slug failed')) {
                    $warningSlugs[] = $context['slug'] ?? null;
                }

                return true;
            });
        // Baseline alert pipeline must remain unaffected — no Log::alert
        // (no prior baseline + within-threshold path).
        Log::shouldReceive('alert')->never();

        $this->app->instance(EvalHarnessRunner::class, $stub);

        $this->artisan('eval:nightly')->assertSuccessful();

        $this->assertContains(
            'contradicting-claims',
            $warningSlugs,
            'Log::warning MUST fire for the failing adversarial slug.',
        );

        // Verify the third slug ran AFTER the failure: the dataset list
        // must include the rejected-approach-trigger run, proving the
        // loop did not abort on the throw.
        $this->assertContains(
            'rag.askmydocs.adversarial.rejected-approach-trigger',
            $stub->datasetsRun,
            'Adversarial loop MUST continue past a per-slug failure.',
        );

        // Surface sidecars: only the slugs that completed produce them.
        $disk->assertExists('eval-harness/nightly/2026-05-10.adversarial.out-of-corpus.summary.json');
        $disk->assertMissing('eval-harness/nightly/2026-05-10.adversarial.contradicting-claims.summary.json');
        $disk->assertExists('eval-harness/nightly/2026-05-10.adversarial.rejected-approach-trigger.summary.json');
    }

    public function test_adversarial_nightly_baseline_failure_skips_adversarial(): void
    {
        config()->set('eval-harness.adversarial_nightly.enabled', true);
        config()->set('eval-harness.adversarial_nightly.datasets', '');

        $disk = Storage::disk($this->diskName);
        $disk->makeDirectory('eval-harness/nightly');

        $stub = new class extends EvalHarnessRunner
        {
            public int $callCount = 0;

            public array $datasetsRun = [];

            public function __construct() {}

            public function run(array $parameters): int
            {
                $this->callCount++;
                $this->datasetsRun[] = (string) ($parameters['dataset'] ?? '');

                // Baseline: exit non-zero AND do NOT write the report
                // file. The command's invokeEvalRun() FAILURE branch
                // hinges on the missing JSON, which forces command
                // FAILURE before the adversarial pass can fire.
                return 1;
            }
        };

        $this->app->instance(EvalHarnessRunner::class, $stub);

        Log::shouldReceive('alert')->andReturnNull();
        Log::shouldReceive('warning')->andReturnNull();

        $this->artisan('eval:nightly')->assertFailed();

        // Only baseline JSON attempt fired (we never even get to the MD
        // run because invokeEvalRun aborts on the missing file). What
        // matters for this test: NO adversarial dataset was attempted.
        foreach ($stub->datasetsRun as $dataset) {
            $this->assertStringNotContainsString(
                '.adversarial.',
                $dataset,
                'Adversarial pass MUST NOT fire when the baseline run fails.',
            );
        }

        foreach ($disk->files('eval-harness/nightly') as $path) {
            $this->assertStringNotContainsString(
                '.adversarial.',
                $path,
                'No adversarial sidecars MUST exist when the baseline failed.',
            );
        }
    }

    /**
     * Recording stub for the adversarial pass tests. Tracks call count
     * + datasets seen, writes JSON or MD depending on the --json flag.
     * Honors --out / --raw-path so the host command's disk lookups
     * still find the files post-run.
     */
    private function bindRecordingRunner(): EvalHarnessRunner
    {
        $disk = Storage::disk($this->diskName);
        $disk->makeDirectory('eval-harness/nightly');
        $payload = $this->reportPayload(macroF1: 0.90);

        $stub = new class($payload) extends EvalHarnessRunner
        {
            public int $callCount = 0;

            public array $datasetsRun = [];

            public function __construct(private string $payload) {}

            public function run(array $parameters): int
            {
                $this->callCount++;
                $this->datasetsRun[] = (string) ($parameters['dataset'] ?? '');

                $out = (string) ($parameters['--out'] ?? '');
                if ($out === '') {
                    return 1;
                }

                $body = ($parameters['--json'] ?? false) === true
                    ? $this->payload
                    : '# stub';
                file_put_contents($out, $body);

                return 0;
            }
        };

        $this->app->instance(EvalHarnessRunner::class, $stub);

        return $stub;
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

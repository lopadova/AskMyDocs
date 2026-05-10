<?php

declare(strict_types=1);

namespace Tests\Feature\Pii;

use App\Pii\Listeners\RedactFailedJobPayload;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use Padosoft\PiiRedactor\RedactorEngine;
use Tests\TestCase;

/**
 * v4.3/W1 sub-PR 4.5 — A2 — RedactFailedJobPayload listener test.
 *
 * The listener fires AFTER Laravel's framework has already inserted
 * a row into `failed_jobs`. We mimic that here by INSERTING the row
 * ourselves with PII-bearing payload + exception, then dispatching a
 * fake JobFailed event and verifying the row was rewritten.
 */
final class FailedJobPayloadRedactionTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        $app['config']->set('pii-redactor.strategy', 'mask');
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('failed_jobs')) {
            Schema::create('failed_jobs', function ($table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->text('connection');
                $table->text('queue');
                $table->longText('payload');
                $table->longText('exception');
                $table->timestamp('failed_at')->useCurrent();
            });
        }
    }

    public function test_default_off_keeps_payload_verbatim(): void
    {
        config()->set('kb.pii_redactor.enabled', false);
        config()->set('kb.pii_redactor.redact_failed_jobs', false);

        $payload = ['email' => 'mario@example.com', 'data' => ['cc' => 'giulia@example.org']];
        $rowId = $this->insertFailedJob($payload, 'failed: paolo@example.it');

        $this->fireJobFailed();

        $row = DB::table('failed_jobs')->where('id', $rowId)->first();
        $decoded = json_decode($row->payload, true);
        $this->assertSame('mario@example.com', $decoded['email']);
        $this->assertStringContainsString('paolo@example.it', $row->exception);
    }

    public function test_both_knobs_on_redact_payload_and_exception(): void
    {
        config()->set('kb.pii_redactor.enabled', true);
        config()->set('kb.pii_redactor.redact_failed_jobs', true);

        $payload = ['email' => 'mario@example.com', 'data' => ['cc' => 'giulia@example.org']];
        $rowId = $this->insertFailedJob($payload, 'failed: paolo@example.it');

        $this->fireJobFailed();

        $row = DB::table('failed_jobs')->where('id', $rowId)->first();
        $decoded = json_decode($row->payload, true);

        // R26 — every persisted string in the payload must be free of
        // raw email patterns.
        $this->assertDoesNotMatchRegularExpression(
            '/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i',
            $row->payload,
            'Re-encoded payload must not retain raw email patterns.',
        );
        $this->assertDoesNotMatchRegularExpression(
            '/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i',
            $row->exception,
            'Exception column must not retain raw email patterns.',
        );

        // Structure preserved.
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('email', $decoded);
        $this->assertArrayHasKey('data', $decoded);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function insertFailedJob(array $payload, string $exception): int
    {
        return (int) DB::table('failed_jobs')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode($payload),
            'exception' => $exception,
            'failed_at' => now(),
        ]);
    }

    private function fireJobFailed(?string $uuid = null): void
    {
        // Mockery shim — implementing every Job contract method by hand
        // is brittle across Laravel minors. Mockery handles abstract
        // method auto-implementation and lets us return a stable queue
        // name for the listener's lookup.
        /** @var JobContract $jobMock */
        $jobMock = Mockery::mock(JobContract::class);
        $jobMock->shouldReceive('getQueue')->andReturn('default');
        $jobMock->shouldReceive('getJobId')->andReturn(null);
        // When a uuid is supplied we encode it into the raw payload
        // envelope (matching the Laravel framework's payload shape)
        // so the listener's `extractJobUuid()` returns it and the row
        // lookup matches deterministically on `failed_jobs.uuid`.
        $rawBody = $uuid === null ? '' : json_encode(['uuid' => $uuid]);
        $jobMock->shouldReceive('getRawBody')->andReturn($rawBody);
        $jobMock->shouldIgnoreMissing();

        $event = new JobFailed('database', $jobMock, new \RuntimeException('boom'));
        $listener = new RedactFailedJobPayload(app(RedactorEngine::class));
        $listener->handle($event);
    }

    /**
     * R16 + finding #1 — multi-worker race: two failures arrive on the
     * same (connection, queue) tuple. Without uuid-aware matching the
     * listener would redact the LATEST row twice (and leave the other
     * unredacted). With uuid extraction in place the listener targets
     * the exact failed-job row by its framework-assigned uuid.
     */
    public function test_uuid_match_redacts_correct_row_in_multi_worker_race(): void
    {
        config()->set('kb.pii_redactor.enabled', true);
        config()->set('kb.pii_redactor.redact_failed_jobs', true);

        $uuidA = (string) Str::uuid();
        $uuidB = (string) Str::uuid();

        // Insert worker-A's row first.
        $rowA = (int) DB::table('failed_jobs')->insertGetId([
            'uuid' => $uuidA,
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['email' => 'mario@example.com']),
            'exception' => 'failed worker A: paolo@example.it',
            'failed_at' => now(),
        ]);

        // Worker-B's row arrives BEFORE the listener for worker-A
        // queries — emulating the race window. With order-by-id-desc
        // matching, this would be redacted instead of worker-A.
        $rowB = (int) DB::table('failed_jobs')->insertGetId([
            'uuid' => $uuidB,
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['email' => 'lucia@example.de']),
            'exception' => 'failed worker B: anna@example.fr',
            'failed_at' => now(),
        ]);

        // Now fire the listener for worker-A's event.
        $this->fireJobFailed($uuidA);

        // Worker-A's row MUST be redacted.
        $rowAAfter = DB::table('failed_jobs')->where('id', $rowA)->first();
        $this->assertDoesNotMatchRegularExpression(
            '/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i',
            $rowAAfter->payload,
            'Worker-A payload must be redacted (matched by uuid).',
        );
        $this->assertDoesNotMatchRegularExpression(
            '/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i',
            $rowAAfter->exception,
            'Worker-A exception must be redacted (matched by uuid).',
        );

        // Worker-B's row MUST be untouched (no JobFailed has fired
        // for it yet from the perspective of this test).
        $rowBAfter = DB::table('failed_jobs')->where('id', $rowB)->first();
        $this->assertSame(
            'lucia@example.de',
            json_decode($rowBAfter->payload, true)['email'],
            'Worker-B payload must NOT be touched by worker-A listener.',
        );
        $this->assertStringContainsString(
            'anna@example.fr',
            $rowBAfter->exception,
            'Worker-B exception must NOT be touched by worker-A listener.',
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}

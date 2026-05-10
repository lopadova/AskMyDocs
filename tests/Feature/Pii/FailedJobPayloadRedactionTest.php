<?php

declare(strict_types=1);

namespace Tests\Feature\Pii;

use App\Pii\Listeners\RedactFailedJobPayload;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode($payload),
            'exception' => $exception,
            'failed_at' => now(),
        ]);
    }

    private function fireJobFailed(): void
    {
        $jobMock = new class implements JobContract
        {
            public function getJobId(): ?string
            {
                return null;
            }

            public function getQueue(): ?string
            {
                return 'default';
            }

            public function uuid(): string
            {
                return 'x';
            }

            public function fire(): void {}

            public function release($delay = 0): void {}

            public function isReleased(): bool
            {
                return false;
            }

            public function isDeleted(): bool
            {
                return false;
            }

            public function isDeletedOrReleased(): bool
            {
                return false;
            }

            public function delete(): void {}

            public function hasFailed(): bool
            {
                return true;
            }

            public function markAsFailed(): void {}

            public function fail($e = null): void {}

            public function attempts()
            {
                return 1;
            }

            public function maxTries()
            {
                return 1;
            }

            public function maxExceptions()
            {
                return null;
            }

            public function backoff()
            {
                return 0;
            }

            public function timeout()
            {
                return 0;
            }

            public function retryUntil()
            {
                return null;
            }

            public function getName()
            {
                return 'TestJob';
            }

            public function resolveName()
            {
                return 'TestJob';
            }

            public function getConnectionName()
            {
                return 'database';
            }

            public function getRawBody()
            {
                return '';
            }

            public function payload()
            {
                return [];
            }
        };

        $event = new JobFailed('database', $jobMock, new \RuntimeException('boom'));
        $listener = new RedactFailedJobPayload(app(RedactorEngine::class));
        $listener->handle($event);
    }
}

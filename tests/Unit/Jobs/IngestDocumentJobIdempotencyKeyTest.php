<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Jobs\IngestDocumentJob;
use Tests\TestCase;

/**
 * The flow idempotency key of an `IngestDocumentJob` attempt: the first
 * attempt keeps the legacy `tenant:project:path[:runKey]` key (a duplicate
 * dispatch short-circuits), a retry is salted with its attempt number so
 * the store never hands a failed run back to the attempt meant to repair it.
 */
final class IngestDocumentJobIdempotencyKeyTest extends TestCase
{
    public function test_the_first_attempt_keeps_the_legacy_key_and_a_retry_is_a_new_key(): void
    {
        $job = new IngestDocumentJob('demo', 'docs/intro.md', 'kb', tenantId: 'acme');

        $this->assertSame('acme:demo:docs/intro.md', $job->idempotencyKeyFor('acme'));
        $this->assertSame('acme:demo:docs/intro.md', $job->idempotencyKeyFor('acme', 1));
        $this->assertSame('acme:demo:docs/intro.md:attempt2', $job->idempotencyKeyFor('acme', 2));
        $this->assertNotSame($job->idempotencyKeyFor('acme', 2), $job->idempotencyKeyFor('acme', 3));
    }

    public function test_the_run_key_salt_and_the_attempt_salt_compose(): void
    {
        $job = new IngestDocumentJob('demo', 'docs/intro.md', 'kb', tenantId: 'acme', runKey: 'ocr-rerun-7');

        $this->assertSame('acme:demo:docs/intro.md:ocr-rerun-7', $job->idempotencyKeyFor('acme', 1));
        $this->assertSame('acme:demo:docs/intro.md:ocr-rerun-7:attempt2', $job->idempotencyKeyFor('acme', 2));
    }

    public function test_a_long_path_is_hashed_and_still_differs_per_attempt(): void
    {
        $job = new IngestDocumentJob('demo', str_repeat('deep/', 60).'intro.md', 'kb', tenantId: 'acme');

        $first = $job->idempotencyKeyFor('acme', 1);
        $second = $job->idempotencyKeyFor('acme', 2);
        $this->assertLessThanOrEqual(255, strlen($first));
        $this->assertLessThanOrEqual(255, strlen($second));
        $this->assertStringStartsWith('acme:demo:h-', $first, 'the hashed form carries the reserved marker');
        $this->assertNotSame($first, $second);
    }

    /**
     * The two forms are disjoint: a document literally named `h-<64 hex>`
     * is hashed too — whatever its length — so it can never share a key with
     * the long path whose digest that is, and one flow cannot short-circuit
     * the other.
     */
    public function test_a_literal_path_that_looks_like_a_hashed_one_cannot_collide_with_it(): void
    {
        $long = str_repeat('deep/', 60).'intro.md';
        $hashedKey = (new IngestDocumentJob('demo', $long, 'kb', tenantId: 'acme'))->idempotencyKeyFor('acme', 1);
        $digest = substr($hashedKey, strlen('acme:demo:h-'));
        $this->assertSame(64, strlen($digest));

        // A short, literal path that spells out exactly that hashed form.
        $impostor = new IngestDocumentJob('demo', 'h-'.$digest, 'kb', tenantId: 'acme');
        $impostorKey = $impostor->idempotencyKeyFor('acme', 1);

        $this->assertNotSame($hashedKey, $impostorKey);
        $this->assertStringStartsWith('acme:demo:h-', $impostorKey, 'a path wearing the marker is hashed, never used verbatim');
    }
}

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

    /**
     * The hashed form digests a structured tuple, not a concatenation: a long
     * path literally ending in `:attempt2` on attempt 1 composes the same
     * bytes as that path on attempt 2, so a concatenated digest would hand a
     * retry another job's key — and with it another job's recorded run.
     */
    public function test_the_hashed_key_separates_the_path_from_the_retry_salt(): void
    {
        $long = str_repeat('deep/', 60).'intro.md';
        $impostor = new IngestDocumentJob('demo', $long.':attempt2', 'kb', tenantId: 'acme');
        $base = new IngestDocumentJob('demo', $long, 'kb', tenantId: 'acme');

        $this->assertNotSame(
            $impostor->idempotencyKeyFor('acme', 1),
            $base->idempotencyKeyFor('acme', 2),
            'a path ending in the salt must not collide with that path on the salted attempt',
        );

        // The run key is separated from the path the same way.
        $withRunKey = new IngestDocumentJob('demo', $long, 'kb', tenantId: 'acme', runKey: 'x');
        $pathCarryingIt = new IngestDocumentJob('demo', $long.':x', 'kb', tenantId: 'acme');
        $this->assertNotSame($withRunKey->idempotencyKeyFor('acme', 1), $pathCarryingIt->idempotencyKeyFor('acme', 1));
    }

    /**
     * The SHORT path is where the collision actually bites: `:` is both the
     * separator and the salt marker, and `KbPath::normalize()` permits it in
     * a client-supplied `source_path`. `docs/a.md` on attempt 2 and
     * `docs/a.md:attempt2` on attempt 1 compose the identical legacy key, so
     * a path carrying a `:` takes the hashed form whatever its length — the
     * retry that must repair a refused publish can then never be handed the
     * other document's recorded run.
     */
    public function test_a_short_path_carrying_a_colon_cannot_collide_with_a_salted_attempt(): void
    {
        $base = new IngestDocumentJob('demo', 'docs/a.md', 'kb', tenantId: 'acme');
        $impostor = new IngestDocumentJob('demo', 'docs/a.md:attempt2', 'kb', tenantId: 'acme');

        $this->assertNotSame($base->idempotencyKeyFor('acme', 2), $impostor->idempotencyKeyFor('acme', 1));
        $this->assertStringStartsWith('acme:demo:h-', $impostor->idempotencyKeyFor('acme', 1), 'an ambiguous path is hashed, never used verbatim');
        // A colon in the RUN KEY is ambiguous the same way.
        $withRunKey = new IngestDocumentJob('demo', 'docs/a.md', 'kb', tenantId: 'acme', runKey: 'r:attempt2');
        $this->assertStringStartsWith('acme:demo:h-', $withRunKey->idempotencyKeyFor('acme', 1));
        $this->assertNotSame($withRunKey->idempotencyKeyFor('acme', 1), $withRunKey->idempotencyKeyFor('acme', 2));
        // And the colon-free short path still keeps the legacy key, so the
        // overwhelming majority of dispatches short-circuit exactly as before.
        $this->assertSame('acme:demo:docs/a.md', $base->idempotencyKeyFor('acme', 1));
    }

    /**
     * The digest binds tenant and project too: without their lengths, a
     * project named `demo:x` under tenant `acme` and a project `x` under
     * tenant `acme:demo` would digest the same bytes.
     */
    public function test_the_hashed_key_separates_the_tenant_from_the_project(): void
    {
        $long = str_repeat('deep/', 60).'intro.md';
        $a = new IngestDocumentJob('demo:x', $long, 'kb', tenantId: 'acme');
        $b = new IngestDocumentJob('x', $long, 'kb', tenantId: 'acme:demo');

        $this->assertNotSame($a->idempotencyKeyFor('acme', 1), $b->idempotencyKeyFor('acme:demo', 1));
    }
}

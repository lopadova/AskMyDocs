<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Kb\Ocr;

use App\Services\Kb\Ocr\OcrService;
use Tests\TestCase;

/**
 * The `metadata.ocr` block is host-owned; a client may still put anything
 * under `metadata`, so a scalar there must be "not forced" and be dropped
 * at the boundary — never a TypeError that turns the ingest into a 500.
 */
final class OcrServiceMetadataTest extends TestCase
{
    public function test_a_scalar_ocr_block_is_not_forced_and_not_a_type_error(): void
    {
        $this->assertFalse(OcrService::isForced(['ocr' => 'x']));
        $this->assertFalse(OcrService::isForced(['ocr' => 1]));
        $this->assertFalse(OcrService::isForced(['ocr' => ['force' => 'yes']]), 'only boolean true forces');
        $this->assertFalse(OcrService::isForced([]));
        $this->assertTrue(OcrService::isForced(['ocr' => ['force' => true]]));
        $this->assertTrue(OcrService::renewRerunLock(['ocr' => 'x']));
        OcrService::releaseRerunLock(['ocr' => 'x']);
    }

    public function test_the_boundary_strips_the_reserved_keys_and_drops_a_scalar_block(): void
    {
        $this->assertSame(['title' => 't'], OcrService::stripTrustedOnlyKeys(['title' => 't', 'ocr' => 'x', 'dry_run' => true]));
        $this->assertSame(['title' => 't'], OcrService::stripTrustedOnlyKeys(['title' => 't', 'ocr' => ['force' => true, 'rerun_lock' => ['key' => 'k', 'owner' => 'o']]]));
        $this->assertSame(['ocr' => ['note' => 'kept']], OcrService::stripTrustedOnlyKeys(['ocr' => ['note' => 'kept', 'force' => true]]));
        // The storage namespace (`disk` / `prefix`) is the host's: a client
        // value would point the queued read at another object.
        $this->assertSame(['title' => 't'], OcrService::stripTrustedOnlyKeys(['title' => 't', 'prefix' => '../x', 'disk' => 'other']));
        // ADR 0030 §4 — the client never sets the version actor.
        $this->assertSame(['title' => 't'], OcrService::stripTrustedOnlyKeys(['title' => 't', 'version_actor' => 'user:9']));
        // ADR 0030 §3 — the retention contract and the dropped-original stamp are host-owned: a client cannot
        // mark a `full_copy` row as one that no longer needs its shared original.
        $this->assertSame(['title' => 't'], OcrService::stripTrustedOnlyKeys(['title' => 't', 'source_retention' => 'reference_only', 'source_dropped' => true]));
        // ADR 0030 §6 — the restore ledger is appended by the trusted restore path only.
        $this->assertSame(['title' => 't'], OcrService::stripTrustedOnlyKeys(['title' => 't', 'restores' => [['actor' => 'user:1', 'at' => '2026-01-01T00:00:00Z']]]));
    }

    public function test_the_persist_step_strips_only_the_run_control_keys_and_keeps_the_trusted_actor(): void
    {
        $this->assertSame(
            ['version_actor' => 'user:9', 'version_reason' => 'r', 'ocr' => ['note' => 'kept']],
            OcrService::stripRunControlKeys(['version_actor' => 'user:9', 'version_reason' => 'r', 'dry_run' => true, 'ocr' => ['note' => 'kept', 'force' => true, 'rerun_lock' => ['key' => 'k', 'owner' => 'o']]]),
        );
        $this->assertSame(['title' => 't'], OcrService::stripRunControlKeys(['title' => 't', 'ocr' => ['force' => true]]));
    }

    public function test_the_persist_step_strips_only_the_run_control_keys_and_keeps_the_storage_namespace(): void
    {
        $this->assertSame(
            ['disk' => 'kb', 'prefix' => 'archive', 'ocr' => ['note' => 'kept']],
            OcrService::stripRunControlKeys(['disk' => 'kb', 'prefix' => 'archive', 'dry_run' => true, 'ocr' => ['note' => 'kept', 'force' => true, 'rerun_lock' => ['key' => 'k', 'owner' => 'o']]]),
        );
    }
}

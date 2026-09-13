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
    }

    public function test_the_persist_step_strips_only_the_run_control_keys_and_keeps_the_storage_namespace(): void
    {
        $this->assertSame(
            ['disk' => 'kb', 'prefix' => 'archive', 'ocr' => ['note' => 'kept']],
            OcrService::stripRunControlKeys(['disk' => 'kb', 'prefix' => 'archive', 'dry_run' => true, 'ocr' => ['note' => 'kept', 'force' => true, 'rerun_lock' => ['key' => 'k', 'owner' => 'o']]]),
        );
    }
}

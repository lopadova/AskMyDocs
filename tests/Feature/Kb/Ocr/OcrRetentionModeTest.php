<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\Ocr;

use App\Services\Kb\Ocr\OcrService;
use App\Services\Kb\Versioning\SourceRetentionResolver;
use Tests\TestCase;

/**
 * ADR 0030 §3 — the retention contract an OCR conversion runs under: the
 * row's stamp when it has one; `full_copy` for a re-run or replay of a row
 * that predates the stamp (the ingestor's rule for a replaced row); the
 * configured mode only for a first conversion.
 */
final class OcrRetentionModeTest extends TestCase
{
    public function test_a_valid_stamp_wins_over_the_configured_mode(): void
    {
        config(['kb.source_retention.mode' => SourceRetentionResolver::REFERENCE_ONLY]);

        $this->assertSame('markdown_only', OcrService::retentionModeOf(['source_retention' => 'markdown_only']));
    }

    public function test_a_first_conversion_takes_the_configured_mode(): void
    {
        config(['kb.source_retention.mode' => SourceRetentionResolver::REFERENCE_ONLY]);

        $this->assertSame(SourceRetentionResolver::REFERENCE_ONLY, OcrService::retentionModeOf(['disk' => 'kb', 'prefix' => '']));
    }

    public function test_a_rerun_or_replay_of_a_stamp_less_row_is_full_copy_never_the_configured_mode(): void
    {
        config(['kb.source_retention.mode' => SourceRetentionResolver::REFERENCE_ONLY]);

        $this->assertSame(SourceRetentionResolver::FULL_COPY, OcrService::retentionModeOf(['ocr' => ['force' => true]]), 'a forced re-run of a legacy row');
        $this->assertSame(SourceRetentionResolver::FULL_COPY, OcrService::retentionModeOf(['converter' => ['provenance' => 'ocr', 'ocr' => ['run' => str_repeat('a', 64)]]]), 'a replay carrying a previous conversion');
        $this->assertSame(SourceRetentionResolver::REFERENCE_ONLY, OcrService::retentionModeOf(['ocr' => ['force' => true], 'source_retention' => 'reference_only']), 'a stamped row keeps its own stamp');
    }

    /**
     * A client payload is not a retention policy: the bag it sends crosses
     * `stripTrustedOnlyKeys()` before the converter reads it, so a forged
     * `converter` block (or a forged stamp) cannot beat the configured
     * `reference_only` on a FIRST conversion.
     */
    public function test_a_stripped_client_bag_cannot_beat_the_configured_mode(): void
    {
        config(['kb.source_retention.mode' => SourceRetentionResolver::REFERENCE_ONLY]);
        $forged = ['title' => 't', 'converter' => ['provenance' => 'ocr', 'ocr' => ['run' => str_repeat('a', 64)]], 'source_retention' => 'full_copy'];

        $this->assertSame(SourceRetentionResolver::FULL_COPY, OcrService::retentionModeOf($forged), 'unstripped, the forgery would win');
        $this->assertSame(SourceRetentionResolver::REFERENCE_ONLY, OcrService::retentionModeOf(OcrService::stripTrustedOnlyKeys($forged)));
    }
}

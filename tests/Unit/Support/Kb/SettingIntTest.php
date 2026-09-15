<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Kb;

use App\Support\Kb\SettingInt;
use Tests\TestCase;

/**
 * SEC-SETTING-SHAPE-001 — a setting that must be a whole number of seconds,
 * days, rows or versions has ONE reading. The shape this replaced
 * (`is_numeric($v) && (int) $v >= $min`) is not the same test: it accepts a
 * value that is not whole and truncates it, which for a lock TTL turns `1.9`
 * into a one-second lease that lets the serialization lapse mid-commit with
 * nobody told.
 */
final class SettingIntTest extends TestCase
{
    public function test_a_whole_number_at_or_above_the_floor_is_the_value(): void
    {
        $this->assertSame(600, SettingInt::whole(600, 1));
        $this->assertSame(600, SettingInt::whole('600', 1));
        $this->assertSame(600, SettingInt::whole(' 600 ', 1), 'an env value keeps its surrounding whitespace');
        $this->assertSame(600, SettingInt::whole(600.0, 1), 'a float that is exactly whole IS whole');
        $this->assertSame(1, SettingInt::whole(1, 1), 'the floor itself is in range');
        $this->assertSame(0, SettingInt::whole(0, 0), 'a floor of zero admits zero — that is how a rotation is disabled');
        $this->assertSame(-5, SettingInt::whole(-5, -10));
    }

    /**
     * The regression this class exists for: a fractional value is a
     * misconfiguration, and the protective default is the honest answer to
     * it. Rounding it into range is how a one-second lock TTL ships.
     */
    public function test_a_value_that_is_not_whole_is_refused_rather_than_truncated(): void
    {
        foreach ([1.9, '1.9', 0.5, '0.5', '600.4', -0.1] as $configured) {
            $this->assertNull(SettingInt::whole($configured, 1), var_export($configured, true));
        }
    }

    public function test_a_value_below_the_floor_is_refused(): void
    {
        $this->assertNull(SettingInt::whole(0, 1));
        $this->assertNull(SettingInt::whole('-1', 0));
    }

    /**
     * A boolean in a seconds setting is a wrong TYPE, and PHP's willingness
     * to cast `true` to 1 is exactly what hides that from the operator.
     */
    public function test_a_wrong_type_is_refused_and_never_cast(): void
    {
        foreach ([true, false, null, [], ['600'], new \stdClass, 'abc', '', '6 hundred', '0x10', INF, NAN] as $configured) {
            $this->assertNull(SettingInt::whole($configured, 0), is_object($configured) ? 'object' : var_export($configured, true));
        }
    }

    /** Scientific notation is not an integer literal: it is refused, not silently expanded. */
    public function test_scientific_notation_is_not_an_integer_literal(): void
    {
        $this->assertNull(SettingInt::whole('1e3', 1));
        $this->assertNull(SettingInt::whole('1E3', 1));
    }

    /**
     * The validator only ever sees what `config/kb.php` hands it. A `(int)`
     * cast on the env read there truncates `0.5` to `0` and `1.9` to `1`
     * BEFORE this class can refuse them, so the strict reading would be left
     * validating values it had already been forced to accept — and for
     * `tmp_max_age_seconds`, a `0` is not a harmless default: when the cache
     * store cannot lease, that threshold is the only thing standing between a
     * live writer's temp and the sweep.
     *
     * So the config declaration for every SettingInt-validated key stays RAW.
     * This asserts it, because the cast is one keystroke and re-reads as a
     * tidy-up.
     */
    public function test_the_config_declaration_of_a_validated_setting_is_not_pre_cast(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 4).'/config/kb.php');
        $keys = [
            'tmp_max_age_seconds', 'source_lock_wait_seconds', 'source_lock_seconds', 'tmp_lease_seconds',
            'orphan_grace_seconds', 'orphan_scan_max_items', 'timeline_limit', 'artifact_state_cache_seconds',
            // v8.36 / ADR 0030 §3 — SourceInFlight validates both through
            // SettingInt::whole(): inflight_reservation_seconds directly
            // (SourceInFlight::seconds()) and job_timeout as the floor its
            // default is built from (SourceInFlight::defaultSeconds()).
            'inflight_reservation_seconds', 'job_timeout',
        ];

        foreach ($keys as $key) {
            $this->assertMatchesRegularExpression(
                "/'".preg_quote($key, '/')."'\s*=>\s*env\(/",
                $source,
                "config/kb.php must hand [{$key}] to SettingInt raw — a cast here validates nothing",
            );
        }
    }
}

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
}

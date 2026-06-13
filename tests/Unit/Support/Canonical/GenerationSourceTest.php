<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Canonical;

use App\Support\Canonical\GenerationSource;
use PHPUnit\Framework\TestCase;

/** v8.11 — the auto-tier provenance discriminator. */
final class GenerationSourceTest extends TestCase
{
    public function test_has_exactly_human_and_auto(): void
    {
        $this->assertSame('human', GenerationSource::Human->value);
        $this->assertSame('auto', GenerationSource::Auto->value);
        $this->assertCount(2, GenerationSource::cases());
    }

    public function test_only_human_is_vouched(): void
    {
        $this->assertTrue(GenerationSource::Human->isHumanVouched());
        $this->assertFalse(GenerationSource::Auto->isHumanVouched());
    }
}

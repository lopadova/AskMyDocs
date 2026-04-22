<?php

namespace Tests\Unit\Kb\Canonical;

use App\Support\Canonical\CanonicalStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CanonicalStatus::class)]
class CanonicalStatusTest extends TestCase
{
    public function test_enum_declares_exactly_six_statuses(): void
    {
        $this->assertCount(6, CanonicalStatus::cases());
    }

    public function test_tryFrom_resolves_each_known_value(): void
    {
        foreach (['draft', 'review', 'accepted', 'superseded', 'deprecated', 'archived'] as $v) {
            $this->assertInstanceOf(CanonicalStatus::class, CanonicalStatus::tryFrom($v), "value must resolve: $v");
        }
    }

    public function test_accepted_and_review_are_retrievable(): void
    {
        $this->assertTrue(CanonicalStatus::Accepted->isRetrievable());
        $this->assertTrue(CanonicalStatus::Review->isRetrievable());
    }

    public function test_other_statuses_are_not_retrievable(): void
    {
        $this->assertFalse(CanonicalStatus::Draft->isRetrievable());
        $this->assertFalse(CanonicalStatus::Superseded->isRetrievable());
        $this->assertFalse(CanonicalStatus::Deprecated->isRetrievable());
        $this->assertFalse(CanonicalStatus::Archived->isRetrievable());
    }

    public function test_penaltyWeight_returns_zero_when_retrievable(): void
    {
        $this->assertSame(0.0, CanonicalStatus::Accepted->penaltyWeight());
        $this->assertSame(0.0, CanonicalStatus::Review->penaltyWeight());
        $this->assertSame(0.0, CanonicalStatus::Draft->penaltyWeight());
    }

    public function test_penaltyWeight_reads_config_for_non_retrievable(): void
    {
        // Without Laravel's config() helper available (pure unit test), the
        // enum must still return a sensible default when config is missing.
        // The enum uses function_exists('config') to stay decoupled from the framework.
        $this->assertGreaterThan(0.0, CanonicalStatus::Superseded->penaltyWeight());
        $this->assertGreaterThan(0.0, CanonicalStatus::Deprecated->penaltyWeight());
        $this->assertGreaterThan(0.0, CanonicalStatus::Archived->penaltyWeight());
    }
}

<?php

namespace Tests\Unit\Kb\Canonical;

use App\Support\Canonical\EdgeType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EdgeType::class)]
class EdgeTypeTest extends TestCase
{
    public function test_enum_declares_exactly_ten_relations(): void
    {
        $this->assertCount(10, EdgeType::cases());
    }

    public function test_tryFrom_resolves_each_known_value(): void
    {
        foreach (['depends_on', 'uses', 'implements', 'related_to', 'supersedes', 'invalidated_by', 'decision_for', 'documented_by', 'affects', 'owned_by'] as $v) {
            $this->assertInstanceOf(EdgeType::class, EdgeType::tryFrom($v), "value must resolve: $v");
        }
    }

    public function test_tryFrom_returns_null_for_unknown_value(): void
    {
        $this->assertNull(EdgeType::tryFrom('unknown_edge'));
        $this->assertNull(EdgeType::tryFrom(''));
    }

    public function test_strong_edges_have_full_weight(): void
    {
        $this->assertSame(1.0, EdgeType::DecisionFor->defaultWeight());
        $this->assertSame(1.0, EdgeType::Implements->defaultWeight());
        $this->assertSame(1.0, EdgeType::Supersedes->defaultWeight());
    }

    public function test_related_to_is_the_weakest_relation(): void
    {
        $this->assertSame(0.5, EdgeType::RelatedTo->defaultWeight());
    }

    public function test_structural_edges_have_intermediate_weight(): void
    {
        $this->assertSame(0.8, EdgeType::DependsOn->defaultWeight());
        $this->assertSame(0.8, EdgeType::Uses->defaultWeight());
        $this->assertSame(0.8, EdgeType::Affects->defaultWeight());
    }

    public function test_ownership_edges_have_soft_weight(): void
    {
        $this->assertSame(0.7, EdgeType::DocumentedBy->defaultWeight());
        $this->assertSame(0.7, EdgeType::InvalidatedBy->defaultWeight());
        $this->assertSame(0.7, EdgeType::OwnedBy->defaultWeight());
    }
}

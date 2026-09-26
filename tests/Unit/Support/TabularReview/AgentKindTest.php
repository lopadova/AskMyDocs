<?php

declare(strict_types=1);

namespace Tests\Unit\Support\TabularReview;

use App\Support\TabularReview\AgentKind;
use PHPUnit\Framework\TestCase;

/**
 * v8.19/W4 (extract/graph/verify) + v8.40/W6 (vision, ADR 0034) — the
 * agentic dimension of a tabular-review column.
 */
final class AgentKindTest extends TestCase
{
    public function test_values_include_every_case(): void
    {
        $this->assertSame(['extract', 'graph', 'verify', 'vision'], AgentKind::values());
    }

    public function test_default_is_extract(): void
    {
        $this->assertSame(AgentKind::EXTRACT, AgentKind::default());
    }

    public function test_from_nullable_round_trip(): void
    {
        $this->assertSame(AgentKind::EXTRACT, AgentKind::fromNullable(null));
        $this->assertSame(AgentKind::EXTRACT, AgentKind::fromNullable(''));
        $this->assertSame(AgentKind::GRAPH, AgentKind::fromNullable('graph'));
        $this->assertSame(AgentKind::VERIFY, AgentKind::fromNullable('verify'));
        $this->assertSame(AgentKind::VISION, AgentKind::fromNullable('vision'));
        // Unknown value degrades to the default rather than throwing (R14).
        $this->assertSame(AgentKind::EXTRACT, AgentKind::fromNullable('bogus'));
    }

    public function test_is_llm_free_is_true_only_for_graph(): void
    {
        $this->assertFalse(AgentKind::EXTRACT->isLlmFree());
        $this->assertTrue(AgentKind::GRAPH->isLlmFree());
        $this->assertFalse(AgentKind::VERIFY->isLlmFree());
        $this->assertFalse(AgentKind::VISION->isLlmFree());
    }

    public function test_is_verify_is_true_only_for_verify(): void
    {
        $this->assertFalse(AgentKind::EXTRACT->isVerify());
        $this->assertFalse(AgentKind::GRAPH->isVerify());
        $this->assertTrue(AgentKind::VERIFY->isVerify());
        $this->assertFalse(AgentKind::VISION->isVerify());
    }

    public function test_is_vision_is_true_only_for_vision(): void
    {
        $this->assertFalse(AgentKind::EXTRACT->isVision());
        $this->assertFalse(AgentKind::GRAPH->isVision());
        $this->assertFalse(AgentKind::VERIFY->isVision());
        $this->assertTrue(AgentKind::VISION->isVision());
    }
}

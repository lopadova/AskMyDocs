<?php

namespace Tests\Unit\Ai;

use App\Ai\Support\ToolTurnDetector;
use Tests\TestCase;

/**
 * Pins the predicate that routes the hybrid OpenAI/OpenRouter providers (and the
 * AiManager metering gate) to the raw-Http branch for any tool turn — including
 * the MCP loop's final answer turn (tool history but no `tools` option).
 */
class ToolTurnDetectorTest extends TestCase
{
    public function test_plain_chat_history_is_not_a_tool_turn(): void
    {
        $this->assertFalse(ToolTurnDetector::historyHasToolTurn([
            ['role' => 'user', 'content' => 'hi'],
            ['role' => 'assistant', 'content' => 'hello'],
            ['role' => 'user', 'content' => 'again'],
        ]));
    }

    public function test_empty_history_is_not_a_tool_turn(): void
    {
        $this->assertFalse(ToolTurnDetector::historyHasToolTurn([]));
    }

    public function test_role_tool_message_is_a_tool_turn(): void
    {
        $this->assertTrue(ToolTurnDetector::historyHasToolTurn([
            ['role' => 'user', 'content' => 'q'],
            ['role' => 'tool', 'tool_call_id' => 'c1', 'name' => 'kb', 'content' => '{}'],
        ]));
    }

    public function test_assistant_with_tool_calls_is_a_tool_turn(): void
    {
        $this->assertTrue(ToolTurnDetector::historyHasToolTurn([
            ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => 'c1']]],
        ]));
    }

    public function test_non_array_entries_are_ignored(): void
    {
        $this->assertFalse(ToolTurnDetector::historyHasToolTurn(['not-an-array', 42, null]));
    }
}

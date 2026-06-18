<?php

declare(strict_types=1);

namespace App\Ai\Support;

/**
 * Detects whether a chat message history belongs to AskMyDocs's MCP tool loop.
 *
 * The laravel/ai SDK cannot represent these history shapes (a `role:'tool'`
 * result message, or an assistant message carrying `tool_calls`), so the hybrid
 * OpenAI/OpenRouter providers must route such a turn to the raw `Http::`
 * `/chat/completions` branch — even when `tools` is ABSENT from `$options`.
 *
 * This is the case for the MCP loop's FINAL answer turn
 * ({@see \App\Mcp\Client\McpToolCallingService::chatWithTools()} line ~141): it
 * is invoked with the original `$options` (no `tools`) but a `$chatHistory` that
 * already contains the assistant `tool_calls` + `role:'tool'` result messages.
 * Routing on `$options['tools']` alone would send it to the SDK path, which
 * throws on the unsupported roles and breaks the loop.
 *
 * Shared by the hybrid providers (routing) and {@see \App\Ai\AiManager} (the
 * metering gate — the residual raw-Http turn must be bridge-metered) so the two
 * decisions never drift.
 */
final class ToolTurnDetector
{
    /**
     * @param  array<int, mixed>  $messages
     */
    public static function historyHasToolTurn(array $messages): bool
    {
        foreach ($messages as $message) {
            if (! is_array($message)) {
                continue;
            }

            if (($message['role'] ?? null) === 'tool') {
                return true;
            }

            if (array_key_exists('tool_calls', $message)) {
                return true;
            }
        }

        return false;
    }
}

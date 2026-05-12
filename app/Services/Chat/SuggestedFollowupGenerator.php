<?php

namespace App\Services\Chat;

use App\Ai\AiManager;
use App\Models\Conversation;
use App\Models\Message;
use Throwable;

/**
 * v4.5/W7 — Tier 2 #10. Produces 2-3 short follow-up prompts after the
 * most recent assistant turn. Used by the chat surface to render
 * pill-button suggestions above the composer; clicking a pill sends
 * the prompt as the next user message.
 *
 * Design choices:
 *   - We re-use the configured chat provider via {@see AiManager::chat()}.
 *     No separate provider knob — keeps the cost model clean ("the same
 *     provider that answered also suggests").
 *   - Generation is best-effort. Provider 5xx, parse failure, empty
 *     response → return `[]` and let the FE simply not render the row.
 *   - Hard caps on input size: only the LAST user+assistant pair is
 *     fed to the prompt. The chat history is irrelevant for follow-up
 *     suggestions and would blow the prompt budget on long threads.
 *   - Output is parsed leniently: JSON first, then a fallback regex
 *     that pulls lines after numeric / dash bullets. Either shape
 *     produced by an LLM is normalized to a clean string[].
 *
 * Trigger: called by `POST /conversations/{id}/suggested-followups`
 * AFTER each assistant turn settles. Not on every render — the FE
 * fires this once on `onFinish` per turn so it doesn't burn provider
 * tokens during streaming.
 */
class SuggestedFollowupGenerator
{
    private const MAX_SUGGESTIONS = 3;
    private const SYSTEM_PROMPT = <<<PROMPT
You are an assistant that proposes the THREE most useful follow-up questions a
user might ask AFTER reading the assistant's most recent reply. Constraints:

- Output STRICTLY a JSON array of three short strings. No prose, no markdown,
  no leading/trailing whitespace outside the array.
- Each string is a complete question (5-15 words). It MUST stand alone — the
  reader will see only the question, not the conversation context.
- Vary the angles: one drills deeper, one widens scope, one challenges or
  asks for a comparison. Avoid redundant questions.
- Use the same language as the assistant's reply.

Example output:
["How does this affect remote workers?", "Compare with the v2 policy", "Why was the previous version rejected?"]
PROMPT;

    /**
     * @return list<string>
     */
    public function generate(Conversation $conversation, AiManager $ai): array
    {
        $pair = $this->fetchLastTurnPair($conversation);
        if ($pair === null) {
            return [];
        }

        $userPrompt = "USER: {$pair['user']}\n\nASSISTANT: {$pair['assistant']}";

        try {
            $response = $ai->chat(
                self::SYSTEM_PROMPT,
                $userPrompt,
                [
                    'max_tokens' => 200,
                    'temperature' => 0.7,
                ],
            );
        } catch (Throwable $e) {
            // Best-effort: failure of a non-essential surface MUST NOT
            // propagate to the user. Mirror ChatLogManager's graceful-
            // degrade pattern.
            return [];
        }

        $parsed = $this->parseSuggestions($response->content);

        return array_slice($parsed, 0, self::MAX_SUGGESTIONS);
    }

    /**
     * @return array{user: string, assistant: string}|null
     */
    private function fetchLastTurnPair(Conversation $conversation): ?array
    {
        $latest = $conversation->messages()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(4)
            ->get();

        // Walk newest-first to find the latest assistant message and
        // the user message that came before it.
        $assistant = null;
        $user = null;
        foreach ($latest as $m) {
            if ($assistant === null && $m->role === 'assistant') {
                $assistant = $m;
                continue;
            }
            if ($assistant !== null && $user === null && $m->role === 'user') {
                $user = $m;
                break;
            }
        }

        if ($assistant === null || $user === null) {
            return null;
        }

        return [
            'user' => $this->truncate($user->content, 1500),
            'assistant' => $this->truncate($assistant->content, 1500),
        ];
    }

    private function truncate(string $text, int $max): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return mb_substr($text, 0, $max).'…';
    }

    /**
     * Parse the LLM output into clean strings. Tries JSON first; falls
     * back to a bullet-pattern parse so a chatty model that ignored
     * the "STRICT JSON" instruction still yields suggestions.
     *
     * @return list<string>
     */
    private function parseSuggestions(string $raw): array
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return [];
        }

        // 1. JSON path — strict; ignore everything else.
        $jsonStart = strpos($trimmed, '[');
        $jsonEnd = strrpos($trimmed, ']');
        if ($jsonStart !== false && $jsonEnd !== false && $jsonEnd > $jsonStart) {
            $candidate = substr($trimmed, $jsonStart, $jsonEnd - $jsonStart + 1);
            $decoded = json_decode($candidate, true);
            if (is_array($decoded)) {
                $out = [];
                foreach ($decoded as $item) {
                    if (is_string($item) && trim($item) !== '') {
                        $out[] = trim($item);
                    }
                }
                if ($out !== []) {
                    return $out;
                }
            }
        }

        // 2. Bullet-pattern fallback.
        $lines = preg_split('/\r?\n/', $trimmed) ?: [];
        $out = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            // Strip leading "1.", "1)", "- ", "* ".
            $clean = preg_replace('/^(?:\d+[.)]\s*|[-*]\s+)/u', '', $line);
            if (is_string($clean) && trim($clean) !== '') {
                $out[] = trim($clean);
            }
            if (count($out) >= self::MAX_SUGGESTIONS) {
                break;
            }
        }

        return $out;
    }
}

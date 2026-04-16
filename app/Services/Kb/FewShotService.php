<?php

namespace App\Services\Kb;

use App\Models\Message;
use Illuminate\Support\Collection;

/**
 * Retrieves positively-rated Q&A pairs to inject as few-shot examples
 * into the system prompt. This enables the AI to learn from user preferences
 * over time — responses that match what users liked get reinforced.
 */
class FewShotService
{
    /**
     * Get recent positively-rated Q&A pairs for a user/project.
     *
     * @return list<array{question: string, answer: string}>
     */
    public function getExamples(int $userId, ?string $projectKey = null, int $limit = 3): array
    {
        // Find assistant messages rated positively
        $positiveMessages = Message::query()
            ->where('rating', 'positive')
            ->where('role', 'assistant')
            ->whereHas('conversation', function ($q) use ($userId, $projectKey) {
                $q->where('user_id', $userId);
                if ($projectKey) {
                    $q->where('project_key', $projectKey);
                }
            })
            ->with('conversation')
            ->orderByDesc('created_at')
            ->limit($limit * 2) // fetch extra to filter
            ->get();

        $examples = [];

        foreach ($positiveMessages as $assistantMsg) {
            // Find the preceding user message in the same conversation
            $userMsg = Message::where('conversation_id', $assistantMsg->conversation_id)
                ->where('role', 'user')
                ->where('created_at', '<', $assistantMsg->created_at)
                ->orderByDesc('created_at')
                ->first();

            if (! $userMsg) {
                continue;
            }

            $examples[] = [
                'question' => mb_substr($userMsg->content, 0, 500),
                'answer' => mb_substr($assistantMsg->content, 0, 1000),
            ];

            if (count($examples) >= $limit) {
                break;
            }
        }

        return $examples;
    }
}

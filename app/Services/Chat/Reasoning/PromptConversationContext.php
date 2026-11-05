<?php

declare(strict_types=1);

namespace App\Services\Chat\Reasoning;

/** Prior task graphs remain in durable memory, not in the current model task. */
final class PromptConversationContext
{
    public static function navigation(array $context): array
    {
        if (array_key_exists('focus', $context)) {
            $context['previous_focus'] = $context['focus'];
        }
        if (array_key_exists('objective', $context)) {
            $context['previous_objective'] = $context['objective'];
        }
        unset($context['focus'], $context['subquestions'], $context['objective']);
        $context['dialogue'] = array_map(static fn ($act) => array_diff_key($act,
            array_flip(['questions', 'answered_questions', 'interpreted_goal'])), $context['dialogue'] ?? []);

        return $context;
    }

    public static function turn(?string $turnContext): ?string
    {
        $data = json_decode($turnContext ?? '', true);
        if (! is_array($data)) {
            return $turnContext;
        }
        foreach (['reasoning', 'original_question_context'] as $key) {
            if (is_array($data[$key] ?? null)) {
                $data[$key] = self::navigation($data[$key]);
            }
        }

        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

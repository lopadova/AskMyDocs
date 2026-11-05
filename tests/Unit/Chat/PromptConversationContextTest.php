<?php

declare(strict_types=1);

namespace Tests\Unit\Chat;

use App\Services\Chat\Reasoning\PromptConversationContext;
use PHPUnit\Framework\TestCase;

final class PromptConversationContextTest extends TestCase
{
    public function test_old_task_graphs_are_removed_without_losing_navigation_or_mutating_memory(): void
    {
        $memory = ['focus' => ['identifiers' => ['ORDER-A'], 'aspect' => 'email'], 'objective' => 'Find emails',
            'subquestions' => [['question' => 'Find ORDER-A emails']], 'known_identifiers' => ['ORDER-A'],
            'references' => [['document_id' => 2]], 'communicated_fact_ids' => ['fact'],
            'dialogue' => [['role' => 'user', 'text' => 'Any emails?', 'interpreted_goal' => 'Find emails', 'questions' => [['question' => 'Find emails']]],
                ['role' => 'assistant', 'sources_shown' => [['document_id' => 2]], 'offered_actions' => ['read_source'], 'answered_questions' => [['question' => 'Find emails']]]]];
        $prompt = PromptConversationContext::navigation($memory);
        $this->assertSame($memory['focus'], $prompt['previous_focus']);
        $this->assertSame('Find emails', $prompt['previous_objective']);
        foreach (['focus', 'objective', 'subquestions'] as $key) {
            $this->assertArrayNotHasKey($key, $prompt);
            $this->assertArrayHasKey($key, $memory);
        }
        $this->assertSame('Any emails?', $prompt['dialogue'][0]['text']);
        $this->assertSame(['ORDER-A'], $prompt['known_identifiers']);
        $this->assertSame(['fact'], $prompt['communicated_fact_ids']);
        $this->assertSame([['document_id' => 2]], $prompt['dialogue'][1]['sources_shown']);
        $this->assertSame(['read_source'], $prompt['dialogue'][1]['offered_actions']);
        $this->assertArrayNotHasKey('interpreted_goal', $prompt['dialogue'][0]);
        $this->assertArrayNotHasKey('answered_questions', $prompt['dialogue'][1]);
    }

    public function test_draft_context_preserves_selection_and_repair_feedback(): void
    {
        $context = ['reasoning' => ['subquestions' => [['question' => 'Old task']]],
            'original_question_context' => ['objective' => 'Previous goal', 'subquestions' => []],
            'current_selection' => ['id' => 'ORDER-A'], 'validation_feedback' => ['checks' => [['reason' => 'original_question_unverified']]]];
        $prompt = json_decode(PromptConversationContext::turn(json_encode($context)), true);
        $this->assertArrayNotHasKey('subquestions', $prompt['reasoning']);
        $this->assertArrayNotHasKey('objective', $prompt['original_question_context']);
        $this->assertSame($context['current_selection'], $prompt['current_selection']);
        $this->assertSame($context['validation_feedback'], $prompt['validation_feedback']);
        $this->assertSame('Previous goal', $prompt['original_question_context']['previous_objective']);
    }

    public function test_non_json_legacy_context_is_retained(): void
    {
        $this->assertSame('Previous textual context', PromptConversationContext::turn('Previous textual context'));
        $this->assertNull(PromptConversationContext::turn(null));
    }
}

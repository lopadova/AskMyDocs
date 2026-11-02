<?php

declare(strict_types=1);

namespace Tests\Unit\Chat;

use App\Services\Chat\Reasoning\FocusContract;
use PHPUnit\Framework\TestCase;

final class FocusContractTest extends TestCase
{
    private function proposal(string $id = 'ORDER-A', bool $contextual = true): array
    {
        $focus = ['topic' => 'order', 'identifiers' => [$id], 'aspect' => 'items', 'fields' => ['items']];
        return ['action' => 'research', 'transition' => $contextual ? 'continue' : 'new',
            'references_previous_turn' => $contextual, 'context_mode' => $contextual ? 'conversation' : 'new',
            'requires_research' => true, 'kb_queries' => [$id.' items'], 'focus' => $focus,
            'subquestions' => [[...$focus, 'question' => 'Which items are in '.$id.'?', 'kb_queries' => [$id.' items'],
                'context_mode' => $contextual ? 'conversation' : 'new', 'requires_research' => true]],
            'resolved_references' => [], 'needs_clarification' => false, 'clarification' => ''];
    }

    public function test_an_omitted_declaration_is_completed_only_for_an_exact_registered_context_identity(): void
    {
        $data = $this->proposal();
        $result = FocusContract::validate($data, ['ORDER-A'], []);
        $this->assertSame(['ORDER-A'], $result['resolved_references']);
        $this->assertSame($data['focus'], $result['focus']);
        $this->assertSame($data['subquestions'], $result['subquestions']);
    }

    public function test_a_current_literal_does_not_become_a_resolved_context_reference(): void
    {
        $this->assertSame([], FocusContract::validate($this->proposal(), [], ['ORDER-A'])['resolved_references']);
    }

    public function test_unknown_focus_identity_is_still_rejected(): void
    {
        $this->expectExceptionMessage('Invalid focus reference.');
        FocusContract::validate($this->proposal('ORDER-UNKNOWN'), ['ORDER-A'], []);
    }

    public function test_a_noncontextual_request_cannot_inherit_a_registered_old_subject(): void
    {
        $this->expectExceptionMessage('Invalid focus reference.');
        FocusContract::validate($this->proposal(contextual: false), ['ORDER-A'], []);
    }

    public function test_an_explicit_unknown_context_reference_is_not_silently_removed(): void
    {
        $data = $this->proposal();
        $data['resolved_references'] = ['UNKNOWN'];
        $this->expectExceptionMessage('Unregistered context reference.');
        FocusContract::validate($data, ['ORDER-A'], []);
    }

    public function test_invalid_shape_is_distinguished_from_an_invalid_identity(): void
    {
        $data = $this->proposal();
        $data['focus']['topic'] = str_repeat('x', 301);
        $this->expectExceptionMessage('Invalid focus shape.');
        FocusContract::validate($data, ['ORDER-A'], []);
    }

    public function test_live_research_does_not_require_an_unrelated_kb_search(): void
    {
        $data = $this->proposal(contextual: false);
        $data['kb_queries'] = $data['subquestions'][0]['kb_queries'] = [];
        $result = FocusContract::validate($data, [], ['ORDER-A']);
        $this->assertTrue($result['subquestions'][0]['requires_research']);
        $this->assertSame([], $result['subquestions'][0]['kb_queries']);
    }

    public function test_mixed_document_and_live_tasks_keep_their_distinct_search_routes(): void
    {
        $data = $this->proposal(contextual: false);
        $live = $this->proposal('ORDER-B', false)['subquestions'][0];
        $live['kb_queries'] = [];
        $data['subquestions'][] = $live;
        $result = FocusContract::validate($data, [], ['ORDER-A', 'ORDER-B']);
        $this->assertSame([['ORDER-A items'], []], array_column($result['subquestions'], 'kb_queries'));
        $this->assertSame([true, true], array_column($result['subquestions'], 'requires_research'));
    }

    public function test_a_task_without_research_still_cannot_propose_kb_searches(): void
    {
        $data = $this->proposal();
        $data['requires_research'] = $data['subquestions'][0]['requires_research'] = false;
        $this->expectExceptionMessage('Invalid research question.');
        FocusContract::validate($data, ['ORDER-A'], []);
    }
}

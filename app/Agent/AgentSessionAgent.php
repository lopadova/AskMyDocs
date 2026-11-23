<?php

declare(strict_types=1);

namespace App\Agent;

use App\Agent\Evidence\AgentEvidenceAccess;
use App\Agent\Evidence\AgentEvidenceEnvelope;
use App\Agent\Grounding\CollectedEvidenceAnswer;
use App\Models\AgentRun;
use App\Services\Chat\QuestionAction;
use App\Services\Chat\QuestionUnderstanding;

/** Owns the conversation. The research tool implements collection and supplies a sourced answer. */
final readonly class AgentSessionAgent
{
    public function __construct(private AgentAnswerSynthesizer $answers) {}

    public function decide(AgentRun $run, AgentEvidenceEnvelope $evidence, ?QuestionUnderstanding $understanding, array $completed = []): AgentLoopOutcome
    {
        $gap = $run->result_json['session_context_gap'] ?? null;
        if ($understanding?->available === false) {
            return $gap === null ? AgentLoopOutcome::assessSession($evidence, null, $completed)
                : new AgentLoopOutcome('request_research', $evidence, $completed);
        }
        if ($understanding?->asksForProvenance()) {
            return new AgentLoopOutcome('answer', $evidence, $completed, 'answer_provenance');
        }
        if (($understanding !== null && $understanding->effectiveFollowUpTarget($gap) !== 'sources')
            || $understanding?->isAssistantConversation() || $understanding?->needsClarification) {
            return new AgentLoopOutcome('answer', $evidence, $completed, 'context_only');
        }
        if ($gap === null && ($run->usesConcurrentSession() || $understanding?->deliverable !== null)) {
            return AgentLoopOutcome::assessSession($evidence, $understanding, $completed);
        }
        if ($gap === null && $understanding !== null && ! $understanding->requiresCurrentObservation() && ! $understanding->needsResearch()) {
            if (! in_array($understanding->effectiveAction(), [QuestionAction::Refine, QuestionAction::Research], true)) {
                return new AgentLoopOutcome('answer', $evidence, $completed, 'context_only');
            }
            if ($evidence->hasEvidence()) {
                return new AgentLoopOutcome('answer', $evidence, $completed, 'context_answer_attempt');
            }
        }

        return new AgentLoopOutcome('request_research', $evidence, $completed);
    }

    public function answer(AgentRun $run, AgentExecutionContext $context, AgentLoopOutcome|AgentSessionResearchResult $input, ?string $turnContext): AgentAnswer
    {
        if ($input instanceof AgentSessionResearchResult) {
            return $input->answer;
        }

        try {
            return $this->answers->synthesize(trim((string) data_get($run->input_json, 'question')), $context, $input, $turnContext,
                $run->result_json['question_understanding'] ?? null, durableRunId: $run->run_id,
                recoverCollectedEvidence: $input->stopReason === 'context_answer_attempt' && $input->evidence->hasEvidence());
        } catch (AgentDraftUnavailable $exception) {
            app(AgentRunControl::class)->ensureActive($run);
            $safe = app(AgentEvidenceAccess::class)->current($run, $context, $input->evidence->jsonSerialize());

            return app(CollectedEvidenceAnswer::class)->recover($run, $safe, $context->locale, $exception);
        }
    }

    public function needsResearch(AgentRun $run, AgentLoopOutcome $sources, AgentAnswer $answer): bool
    {
        if (! $answer->needsSessionSources()) {
            return false;
        }
        if ($sources->stopReason === 'context_answer_attempt') {
            return true;
        }
        $understanding = $run->result_json['question_understanding'] ?? [];

        return $sources->stopReason === 'context_only' && ! isset($run->result_json['session_context_gap'])
            && ($understanding['follow_up_target'] ?? null) === 'previous_reply'
            && ($understanding['deliverable']['kind'] ?? null) !== 'rewrite'
            && in_array($answer->grounding['coverage_validation']['next_action'] ?? null, ['read', 'search'], true);
    }
}

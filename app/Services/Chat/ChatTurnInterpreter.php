<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Agent\Capabilities\AgentCapabilitySnapshot;
use App\Models\KbRetrievalProfile;
use App\Models\User;
use App\Services\Chat\Reasoning\ConversationReasoning;
use App\Services\Kb\Retrieval\RetrievalFilters;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;

/** Interpret the turn before invoking retrieval; the capability inventory is already authorized. */
final readonly class ChatTurnInterpreter
{
    public function __construct(private ChatQuestionPreprocessor $preprocessor) {}

    public function prepare(string $question, ?string $projectKey, ?RetrievalFilters $filters = null,
        ?string $conversationContext = null, ?User $actor = null, ?Model $owner = null, ?Model $turn = null,
        ?AgentCapabilitySnapshot $capabilities = null, ?array $initialPlanning = null): ?QuestionUnderstanding
    {
        if (! config('reasoning.enabled') && ! config('kb.investigation.enabled', true)) {
            return null; // Explicit rollback keeps legacy transports operational.
        }
        $project = $projectKey;
        if ($project === null && count($filters?->projectKeys ?? []) === 1) {
            $project = $filters->projectKeys[0];
        }
        $profile = $project === null ? null : KbRetrievalProfile::query()->forTenant(app(TenantContext::class)->current())
            ->where('project_key', $project)->first();
        $data = $profile === null ? null : ['context' => $profile->company_context, 'glossary' => $profile->glossary ?? [],
            'relevant_entities' => $profile->relevant_entities ?? [], 'expected_facts' => $profile->expected_facts ?? [],
            'preferred_source_types' => $profile->preferred_source_types ?? []];
        $memory = app(ConversationReasoning::class);
        if ($memory->enabled() && $owner !== null && $turn !== null && $owner->project_key === $projectKey) {
            return $memory->prepare($owner, $turn, $question, $data, $actor, $filters, $capabilities, $initialPlanning);
        }
        return $this->preprocessor->interpret($question, $conversationContext, $data, $memory->enabled() ? [] : null, $capabilities, $initialPlanning);
    }
}

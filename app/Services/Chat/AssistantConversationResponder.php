<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Agent\AgentAnswer;
use App\Agent\AgentExecutionContext;
use App\Agent\Capabilities\AgentCapabilitySnapshotBuilder;
use App\Agent\Tools\AgentLiveSourceSelection;
use App\Agent\Tools\AgentToolDefinition;
use App\Agent\Tools\AgentToolRegistry;
use App\Models\User;
use App\Services\Widget\WidgetPiiMasker;

/** Conversational replies use host capabilities, never similarity-matched business documents. */
final readonly class AssistantConversationResponder
{
    public function answer(QuestionUnderstanding $understanding, AgentExecutionContext $context, ?User $actor = null, ?array $selection = null): AgentAnswer
    {
        if (! $understanding->isAssistantConversation()) {
            throw new \InvalidArgumentException('assistant_conversation_required');
        }
        $italian = str_starts_with($context->locale, 'it');
        if ($understanding->effectiveAction() === QuestionAction::Greeting) {
            return new AgentAnswer($italian ? 'Ciao! Come posso aiutarti?' : 'Hello! How can I help you?',
                $context->locale, 'complete', [], [], grounding: ['status' => 'conversational', 'action' => 'greeting']);
        }
        $tools = array_filter(app(AgentToolRegistry::class)->forContext($context, $actor),
            static fn (AgentToolDefinition $tool): bool => $tool->readOnly && ! ($tool->metadata['confirmation_required'] ?? false));
        $tools = app(AgentLiveSourceSelection::class)->apply($tools, $selection);
        $snapshot = app(AgentCapabilitySnapshotBuilder::class)->build($tools);
        $topic = strtolower($understanding->focus['topic'] ?? 'assistant');
        $documents = array_filter($tools, static fn ($tool) => in_array($tool->kind, ['knowledge', 'catalog'], true)) !== [];
        $live = array_filter($snapshot->capabilities, static fn ($capability) => in_array($capability->source, ['api', 'mcp', 'client'], true));
        if (! in_array($topic, ['assistant', 'documents', 'live_tools'], true)) {
            // Match only the declared canonical entity, not words in a user question or remote description.
            $live = array_filter($live, static fn ($capability) => rtrim($capability->entity, 's') === rtrim($topic, 's'));
        }
        $parts = [];
        if (in_array($topic, ['assistant', 'documents'], true)) {
            $parts[] = $documents
                ? ($italian ? 'Posso cercare e leggere i documenti indicizzati del progetto corrente, nei limiti dei tuoi permessi.'
                    : 'I can search and read indexed documents in the current project, within your permissions.')
                : ($italian ? 'In questa chat non è disponibile la consultazione dei documenti indicizzati.'
                    : 'Indexed-document lookup is not available in this chat.');
        }
        if ($topic !== 'documents') {
            if ($live === []) {
                $parts[] = $italian ? 'Per i dati richiesti non risultano strumenti di lettura disponibili nelle fonti selezionate.'
                    : 'No read tools for the requested data are available in the selected sources.';
            } else {
                $names = array_values(array_unique(array_map(static fn ($capability) => app(WidgetPiiMasker::class)->maskString(
                    mb_substr(preg_replace('/[\r\n]+/', ' ', strip_tags($tools[$capability->tool]->displayName)) ?? '', 0, 150)), $live)));
                $parts[] = ($italian ? 'Sono disponibili queste funzioni di lettura: ' : 'These read functions are available: ').implode(', ', $names).'.';
                $parts[] = $italian ? 'La disponibilità delle funzioni non conferma che il servizio risponda o che uno specifico dato esista: questo va verificato con una richiesta.'
                    : 'Available functions do not confirm that the service responds or that a specific record exists; that requires a lookup.';
            }
        }
        return new AgentAnswer(implode("\n\n", $parts), $context->locale, 'complete', [], [], grounding: [
            'status' => 'host_verified', 'action' => 'capabilities', 'capability_hash' => $snapshot->hash,
            'scope' => ['tenant_id' => $context->tenantId, 'project_key' => $context->projectKey],
            'available_tools' => array_keys($tools), 'connectivity_verified' => false,
        ]);
    }
}

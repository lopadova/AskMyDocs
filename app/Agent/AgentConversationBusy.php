<?php

declare(strict_types=1);

namespace App\Agent;

final class AgentConversationBusy extends \RuntimeException
{
    public function __construct(public readonly string $locale)
    {
        parent::__construct(str_starts_with(strtolower($locale), 'it')
            ? 'Un attimo, una cosa alla volta. Sto ancora completando la richiesta precedente.'
            : 'One moment, one thing at a time. I am still finishing your previous request.');
    }

    public function voiceResponse(): array
    {
        return ['busy' => true, 'retry' => false, 'response' => [
            'answer' => $this->getMessage(), 'locale' => $this->locale,
            'citations' => [], 'completeness' => 'insufficient',
        ]];
    }
}

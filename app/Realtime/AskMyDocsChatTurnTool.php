<?php

declare(strict_types=1);

namespace App\Realtime;

use AgentsFullDuplex\RealtimeAgent\Confirmation;
use AgentsFullDuplex\RealtimeAgent\Contracts\ToolContract;
use AgentsFullDuplex\RealtimeAgent\Data\ToolDefinition;
use AgentsFullDuplex\RealtimeAgent\Enums\ToolTarget;
use AgentsFullDuplex\RealtimeAgent\Tool;

final class AskMyDocsChatTurnTool implements ToolContract
{
    public function definition(): ToolDefinition
    {
        return Tool::make('askmydocs.chat_turn')
            ->description('Submit one user question to the canonical AskMyDocs agent pipeline. Always use this tool for substantive answers and read the returned canonical response verbatim.')
            ->input([
                'type' => 'object',
                'properties' => [
                    'question' => [
                        'type' => 'string',
                        'minLength' => 1,
                        'maxLength' => 10_000,
                        'description' => 'The user request exactly as understood from the current voice or text turn.',
                    ],
                ],
                'required' => ['question'],
                'additionalProperties' => false,
            ])
            ->target(ToolTarget::Server)
            ->handler(HandleRealtimeChatTurn::class)
            ->authorize(AuthorizeRealtimeChatTurn::class)
            ->confirmation(Confirmation::never());
    }
}

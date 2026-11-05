<?php

declare(strict_types=1);

namespace App\Services\Chat;

/** Relation to the dialogue is separate from the need for new observations. */
enum QuestionContextMode: string
{
    case Conversation = 'conversation';
    case NewRequest = 'new';
    case Mixed = 'mixed';

    public static function schema(): array
    {
        return ['type' => 'string', 'enum' => array_column(self::cases(), 'value')];
    }
}

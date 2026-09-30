<?php

declare(strict_types=1);

namespace App\Services\Chat;

/** Conversational intent, interpreted once by the model; never an authorization. */
enum QuestionAction: string
{
    public const ANSWER_INSTRUCTIONS = 'Use the server interpretation action, not keywords: read_source requests the original authorized source text, provenance its recorded attribution, refine only additional requested details, recap historical information, and recheck verification of the earlier conclusion. Do not treat previous assistant text as evidence. Ask for clarification if the source target is ambiguous; never guess a source or imply that a historical observation is current.';

    case Research = 'research';
    case Refine = 'refine';
    case ReadSource = 'read_source';
    case Provenance = 'provenance';
    case Recap = 'recap';
    case Recheck = 'recheck';
    case Clarify = 'clarify';

    public static function schema(): array
    {
        return ['type' => 'string', 'enum' => array_column(self::cases(), 'value')];
    }
}

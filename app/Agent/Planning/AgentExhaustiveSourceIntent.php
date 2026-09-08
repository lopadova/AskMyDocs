<?php

declare(strict_types=1);

namespace App\Agent\Planning;

final class AgentExhaustiveSourceIntent
{
    public function matches(string $question): bool
    {
        $normalized = $this->normalize($question);
        if ($normalized === '') {
            return false;
        }

        foreach ([
            '/\b(?:cerca|trova|raccogli|recupera)\b.{0,100}\b(?:tutto|tutte|ogni|qualsiasi|ovunque)\b/',
            '/\b(?:tutto|tutte le informazioni|ogni informazione|qualsiasi informazione)\b.{0,100}\b(?:trova|trovare|disponibile|riesci|puoi)\b/',
            '/\b(?:find|search|gather|collect)\b.{0,100}\b(?:everything|all information|all available|every source|everywhere)\b/',
            '/\b(?:everything you can find|all information available|search everywhere|all sources)\b/',
        ] as $pattern) {
            if (preg_match($pattern, $normalized) === 1) {
                return true;
            }
        }

        return false;
    }

    private function normalize(string $value): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', mb_strtolower($value));

        return trim(preg_replace('/[^a-z0-9]+/', ' ', is_string($ascii) ? $ascii : $value) ?? '');
    }
}

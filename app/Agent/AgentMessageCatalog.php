<?php

declare(strict_types=1);

namespace App\Agent;

use App\Support\SupportedLocale;
use Illuminate\Support\Facades\Lang;

final class AgentMessageCatalog
{
    /**
     * @param array<string,scalar|null> $parameters
     * @return array{locale:string,message_key:string,message_params:array<string,scalar|null>,message:string}
     */
    public function format(string $locale, string $key, array $parameters = []): array
    {
        $locale = SupportedLocale::normalize($locale);
        $catalog = SupportedLocale::catalog($locale);
        $translationKey = 'agent.'.$key;
        $message = Lang::get($translationKey, $parameters, $catalog);

        if (! is_string($message) || $message === $translationKey) {
            $message = (string) Lang::get('agent.unknown', [], $catalog);
        }

        return [
            'locale' => $locale,
            'message_key' => $key,
            'message_params' => $parameters,
            'message' => $message,
        ];
    }
}

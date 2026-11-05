<?php

declare(strict_types=1);

namespace App\Services\Chat;

use Padosoft\PiiRedactor\RedactorEngine;

final readonly class ChatInputRedactor
{
    public function __construct(private RedactorEngine $engine) {}

    public function redact(string $content): string
    {
        $config = config('kb.pii_redactor');

        if ($content === ''
            || ! ($config['enabled'] ?? false)
            || ! ($config['persist_chat_redacted'] ?? false)) {
            return $content;
        }

        return $this->engine->redact($content);
    }
}

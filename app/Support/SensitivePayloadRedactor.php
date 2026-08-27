<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\Widget\WidgetPiiMasker;

/** Recursively redacts credential-shaped keys and PII-like string values. */
final readonly class SensitivePayloadRedactor
{
    private const REDACTED = '[REDACTED]';

    public function __construct(private WidgetPiiMasker $masker) {}

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function redact(array $payload): array
    {
        $masked = [];

        foreach ($payload as $key => $value) {
            if ($this->isSensitiveKey((string) $key)) {
                $masked[$key] = self::REDACTED;

                continue;
            }

            if (is_array($value)) {
                $masked[$key] = $this->redact($value);
            } elseif (is_string($value)) {
                $masked[$key] = $this->masker->maskString($value);
            } else {
                $masked[$key] = $value;
            }
        }

        return $masked;
    }

    private function isSensitiveKey(string $key): bool
    {
        $snakeCase = preg_replace('/(?<!^)[A-Z]/', '_$0', $key) ?? $key;
        $normalized = strtolower(str_replace(['-', ' '], '_', $snakeCase));

        return preg_match(
            '/(?:^|_)(?:authorization|password|passwd|secret|token|api_key|apikey|cookie|credential|private_key|client_secret|access_token|refresh_token|bearer)(?:$|_)/',
            $normalized,
        ) === 1;
    }
}

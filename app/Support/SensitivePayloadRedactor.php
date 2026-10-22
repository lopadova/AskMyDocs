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
        return $this->redactNested($payload, 0);
    }

    private function redactNested(array $payload, int $depth): array
    {
        $masked = [];

        foreach ($payload as $key => $value) {
            if ($this->isSensitiveKey((string) $key)) {
                $masked[$key] = self::REDACTED;

                continue;
            }

            if (is_array($value)) {
                $masked[$key] = $depth >= 32 ? '[REDACTED:depth-limit]' : $this->redactNested($value, $depth + 1);
            } elseif (is_string($value)) {
                $masked[$key] = $this->redactText($value, $depth);
            } elseif (is_object($value) || is_resource($value)) {
                $masked[$key] = '[REDACTED:non-scalar]';
            } else {
                $masked[$key] = $value;
            }
        }

        return $masked;
    }

    private function redactText(string $value, int $depth): string
    {
        // Some providers place a JSON payload inside an exception string.
        $decoded = json_decode($value, true, 32);
        if (is_array($decoded)) {
            return $depth >= 32 ? '[REDACTED:depth-limit]' : (string) json_encode(
                $this->redactNested($decoded, $depth + 1), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
            );
        }
        $value = preg_replace('~([a-z][a-z0-9+.-]*://)[^\s/@]+(?::[^\s/@]*)?@~i', '$1[REDACTED]@', $value) ?? $value;
        $value = preg_replace('/\b(Bearer|Basic)\s+[a-zA-Z0-9+\/.=_-]+/i', '$1 [REDACTED]', $value) ?? $value;
        $value = preg_replace(
            '/\b(authorization|passwords?|passwd|secrets?|token|api[_-]?key|cookies?|credentials?|private[_-]?key|client[_-]?secret|access[_-]?token|refresh[_-]?token)["\']?\s*[=:]\s*("(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'|[^\s&;,<>\)]+)/i',
            '$1=[REDACTED]',
            $value,
        ) ?? $value;

        return $this->masker->maskString($value);
    }

    private function isSensitiveKey(string $key): bool
    {
        $snakeCase = preg_replace('/(?<!^)[A-Z]/', '_$0', $key) ?? $key;
        $normalized = strtolower(str_replace(['-', ' '], '_', $snakeCase));

        return preg_match(
            '/(?:^|_)(?:authorization|passwords?|passwd|secrets?|token|api_key|apikey|cookies?|credentials?|private_key|client_secret|access_token|refresh_token|bearer)(?:$|_)/',
            $normalized,
        ) === 1;
    }
}

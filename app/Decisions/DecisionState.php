<?php

declare(strict_types=1);

namespace App\Decisions;

use App\Services\Widget\WidgetPiiMasker;

/** The same sanitized UTF-8 state is measured by the packer and the provider gate. */
final readonly class DecisionState
{
    private function __construct(public array $data, public int $bytes) {}

    public static function fromArray(array $state): self
    {
        $data = app(WidgetPiiMasker::class)->maskArray(self::redactSensitiveKeys($state));
        if (! is_array($data)) {
            throw new DecisionException('Decision state could not be sanitized.');
        }

        return new self($data, strlen(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)));
    }

    public static function maxBytes(): int
    {
        return max(0, (int) config('decisions.max_state_bytes', 32768));
    }

    public function exceedsLimit(): bool
    {
        return $this->bytes > self::maxBytes();
    }

    /** @param array<mixed> $data @return array<mixed> */
    private static function redactSensitiveKeys(array $data): array
    {
        // Preserve the existing secret-key policy at every nesting level. Short
        // values can grow to [REDACTED], so measure AFTER this and PII masking.
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match('/(?:password|secret|api[_-]?key|authorization|access[_-]?token|refresh[_-]?token|credential)/i', $key)) {
                $data[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $data[$key] = self::redactSensitiveKeys($value);
            }
        }

        return $data;
    }
}

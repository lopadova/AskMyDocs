<?php

declare(strict_types=1);

namespace App\Services\Invite\Support;

use RuntimeException;

/**
 * Raised by CodeGenerator. `errorCode` carries the canonical generation error
 * string (docs/05-code-generation.md): invalid_alphabet, length_too_short,
 * collision_exhausted, vanity_taken, vanity_reserved, vanity_profane,
 * vanity_malformed, sign_payload_invalid, sign_secret_unavailable.
 */
final class CodeGenerationException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message = '')
    {
        parent::__construct($message !== '' ? $message : $errorCode);
    }

    public static function of(string $errorCode, string $message = ''): self
    {
        return new self($errorCode, $message);
    }
}

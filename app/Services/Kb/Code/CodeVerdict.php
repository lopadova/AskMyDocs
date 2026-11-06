<?php

declare(strict_types=1);

namespace App\Services\Kb\Code;

/**
 * The outcome of {@see CodeIngestionPolicy}: accepted, or refused with a
 * stable machine-readable reason (never the file content).
 */
final readonly class CodeVerdict
{
    public const DISABLED = 'code_ingestion_disabled';
    public const UNSUPPORTED = 'unsupported_extension';
    public const DENIED_PATH = 'denied_path';
    public const TOO_LARGE = 'too_large';
    public const GENERATED = 'generated_file';
    public const BINARY = 'binary_content';

    private function __construct(
        public bool $accepted,
        public ?string $reason,
        public ?string $detail,
    ) {}

    public static function accept(): self
    {
        return new self(true, null, null);
    }

    public static function refuse(string $reason, ?string $detail = null): self
    {
        return new self(false, $reason, $detail);
    }

    public function label(): string
    {
        return $this->reason === null ? 'accepted' : $this->reason.($this->detail !== null ? ': '.$this->detail : '');
    }
}

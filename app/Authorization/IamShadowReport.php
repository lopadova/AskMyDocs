<?php

declare(strict_types=1);

namespace App\Authorization;

final readonly class IamShadowReport
{
    public function __construct(
        public bool $known,
        public bool $allowed,
        public bool $authoritative,
        public bool $shadow,
        public bool $diverged,
        public string $reason,
    ) {}
}

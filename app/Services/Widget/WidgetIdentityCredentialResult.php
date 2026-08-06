<?php

declare(strict_types=1);

namespace App\Services\Widget;

use App\Models\WidgetKey;

/**
 * Plaintext is deliberately short-lived: callers may render it once, while
 * persistence and audit receive only the key model and its monotonic version.
 */
final readonly class WidgetIdentityCredentialResult
{
    public function __construct(
        public WidgetKey $key,
        public ?string $plainSecret = null,
    ) {}
}

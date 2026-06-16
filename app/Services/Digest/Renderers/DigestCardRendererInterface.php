<?php

declare(strict_types=1);

namespace App\Services\Digest\Renderers;

use App\Services\Digest\DigestPayload;

/**
 * v8.15/W2 — renders a {@see DigestPayload} into a channel-specific webhook
 * JSON payload (Discord embed / Slack Block Kit / Teams Adaptive Card).
 *
 * One renderer per channel; the {@see DigestRendererRegistry} enforces that the
 * `channel()` keys do not overlap (R23 mutex) and that every registered class
 * implements this interface.
 */
interface DigestCardRendererInterface
{
    /** The channel key this renderer serves: 'discord' | 'slack' | 'teams'. */
    public function channel(): string;

    /**
     * @return array<string, mixed> JSON-encodable webhook payload
     */
    public function render(DigestPayload $payload): array;
}

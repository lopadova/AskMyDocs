<?php

declare(strict_types=1);

namespace App\Services\Digest\Renderers;

use App\Services\Digest\DigestPayload;

/**
 * v8.15/W2 — renders the digest as a Discord rich embed (same `embeds` format
 * the per-event {@see \App\Notifications\Channels\DiscordChannel} uses).
 */
final class DiscordDigestRenderer extends AbstractDigestCardRenderer
{
    public function channel(): string
    {
        return 'discord';
    }

    public function render(DigestPayload $payload): array
    {
        $fields = [];

        $fields[] = ['name' => 'At a glance', 'value' => $this->metricsLine($payload), 'inline' => false];

        $newDocs = $this->newDocLines($payload);
        if ($newDocs !== []) {
            $fields[] = ['name' => '🆕 New & promoted', 'value' => implode("\n", $newDocs), 'inline' => false];
        }

        $stale = $this->staleLines($payload);
        if ($stale !== []) {
            $fields[] = ['name' => '🕓 Needs review', 'value' => implode("\n", $stale), 'inline' => false];
        }

        $gaps = $this->topGapLines($payload);
        if ($gaps !== []) {
            $fields[] = ['name' => '❓ Top unanswered', 'value' => implode("\n", $gaps), 'inline' => false];
        }

        $embed = [
            'title' => $this->title($payload),
            'description' => $this->summary($payload),
            'color' => self::BRAND_COLOR_INT,
            'footer' => ['text' => $payload->periodLabel()],
        ];
        if ($fields !== []) {
            $embed['fields'] = $fields;
        }

        return ['embeds' => [$embed]];
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Digest\Renderers;

use App\Services\Digest\DigestPayload;

/**
 * v8.15/W2 — renders the digest as Slack Block Kit (same `blocks` family the
 * per-event {@see \App\Notifications\Channels\SlackChannel} uses).
 */
final class SlackDigestRenderer extends AbstractDigestCardRenderer
{
    public function channel(): string
    {
        return 'slack';
    }

    public function render(DigestPayload $payload): array
    {
        $blocks = [
            ['type' => 'header', 'text' => ['type' => 'plain_text', 'text' => $this->title($payload), 'emoji' => true]],
            ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => $this->summary($payload)]],
            ['type' => 'context', 'elements' => [['type' => 'mrkdwn', 'text' => $this->metricsLine($payload)]]],
        ];

        $this->appendSection($blocks, '*🆕 New & promoted*', $this->newDocLines($payload));
        $this->appendSection($blocks, '*🕓 Needs review*', $this->staleLines($payload));
        $this->appendSection($blocks, '*❓ Top unanswered*', $this->topGapLines($payload));

        $blocks[] = ['type' => 'context', 'elements' => [['type' => 'mrkdwn', 'text' => $payload->periodLabel()]]];

        return [
            'text' => $this->title($payload),  // fallback for notifications
            'blocks' => $blocks,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     * @param  list<string>  $lines
     */
    private function appendSection(array &$blocks, string $heading, array $lines): void
    {
        if ($lines === []) {
            return;
        }

        $blocks[] = [
            'type' => 'section',
            'text' => ['type' => 'mrkdwn', 'text' => $heading."\n".implode("\n", $lines)],
        ];
    }
}

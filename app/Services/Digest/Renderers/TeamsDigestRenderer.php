<?php

declare(strict_types=1);

namespace App\Services\Digest\Renderers;

use App\Services\Digest\DigestPayload;

/**
 * v8.15/W2 — renders the digest as a Microsoft Teams Adaptive Card 1.4 wrapped
 * in the connector message envelope (same shape the per-event
 * {@see \App\Notifications\Channels\TeamsChannel} uses).
 */
final class TeamsDigestRenderer extends AbstractDigestCardRenderer
{
    public function channel(): string
    {
        return 'teams';
    }

    public function render(DigestPayload $payload): array
    {
        $body = [
            ['type' => 'TextBlock', 'text' => $this->title($payload), 'weight' => 'Bolder', 'size' => 'Large', 'wrap' => true],
            ['type' => 'TextBlock', 'text' => $payload->periodLabel(), 'isSubtle' => true, 'spacing' => 'None', 'wrap' => true],
            ['type' => 'TextBlock', 'text' => $this->summary($payload), 'wrap' => true],
            ['type' => 'TextBlock', 'text' => $this->metricsLine($payload), 'wrap' => true, 'spacing' => 'Small', 'isSubtle' => true],
        ];

        $this->appendBlock($body, '🆕 New & promoted', $this->newDocLines($payload));
        $this->appendBlock($body, '🕓 Needs review', $this->staleLines($payload));
        $this->appendBlock($body, '❓ Top unanswered', $this->topGapLines($payload));

        return [
            'type' => 'message',
            'attachments' => [[
                'contentType' => 'application/vnd.microsoft.card.adaptive',
                'content' => [
                    'type' => 'AdaptiveCard',
                    '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                    'version' => '1.4',
                    'body' => $body,
                ],
            ]],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $body
     * @param  list<string>  $lines
     */
    private function appendBlock(array &$body, string $heading, array $lines): void
    {
        if ($lines === []) {
            return;
        }

        $body[] = ['type' => 'TextBlock', 'text' => $heading, 'weight' => 'Bolder', 'spacing' => 'Medium', 'wrap' => true];
        $body[] = ['type' => 'TextBlock', 'text' => implode("\n\n", $lines), 'wrap' => true, 'spacing' => 'None'];
    }
}

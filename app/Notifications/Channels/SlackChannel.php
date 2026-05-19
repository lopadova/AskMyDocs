<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use App\Models\User;
use App\Notifications\Events\BaseNotificationEvent;

/**
 * v8.0/W2.1 — Slack incoming-webhook channel adapter.
 *
 * Posts to a `https://hooks.slack.com/services/...` URL. Slack
 * webhooks authenticate via the URL itself (the token is part of
 * the path) — no signing header. The payload uses Slack's Block Kit
 * format so the message renders with a header + section + context
 * row in the target Slack channel:
 *
 *   {
 *     "text": "<plain-text fallback for notification previews>",
 *     "blocks": [
 *       { "type": "header", "text": {...} },
 *       { "type": "section", "text": {...} },
 *       { "type": "context", "elements": [...] }   // optional
 *     ]
 *   }
 *
 * Slack also accepts the legacy `attachments` format but Block Kit
 * is the documented forward-compatible API and produces nicer
 * rendering in modern Slack clients.
 *
 * See {@see AbstractWebhookChannel} for the rest of the lifecycle.
 */
final class SlackChannel extends AbstractWebhookChannel
{
    public function name(): string
    {
        return 'slack';
    }

    protected function configKey(): string
    {
        return 'askmydocs.notifications.channels.slack';
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildPayload(BaseNotificationEvent $event, ?User $user): array
    {
        $eventType = $event->eventType();
        $payload = $event->payload();

        $title = NotificationSubjects::forEventType($eventType);
        $summary = NotificationSummaries::forEventType($eventType, $payload);

        $blocks = [
            [
                'type' => 'header',
                'text' => [
                    'type' => 'plain_text',
                    'text' => $title,
                    'emoji' => true,
                ],
            ],
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => $summary,
                ],
            ],
        ];

        $contextElements = [];
        foreach (['project_key', 'slug', 'doc_id', 'change', 'actor', 'collection_slug'] as $key) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }
            $value = $payload[$key];
            if ($value === null || $value === '') {
                continue;
            }
            $contextElements[] = [
                'type' => 'mrkdwn',
                'text' => '*'.str_replace('_', ' ', ucfirst($key)).":* `".(is_scalar($value) ? (string) $value : json_encode($value))."`",
            ];
        }
        if ($contextElements !== []) {
            $blocks[] = [
                'type' => 'context',
                'elements' => $contextElements,
            ];
        }

        return [
            'text' => $title.' — '.$summary,
            'blocks' => $blocks,
        ];
    }
}

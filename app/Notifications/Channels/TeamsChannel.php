<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use App\Models\User;
use App\Notifications\Events\BaseNotificationEvent;

/**
 * v8.0/W2.1 — Microsoft Teams incoming-webhook channel adapter.
 *
 * Posts to a Microsoft Teams Incoming Webhook connector URL
 * (`https://<tenant>.webhook.office.com/webhookb2/<id>/IncomingWebhook/<token>`).
 * Authentication is baked into the URL token. The payload uses the
 * Adaptive Card 1.4 schema wrapped in the connector envelope Teams
 * expects:
 *
 *   {
 *     "type": "message",
 *     "attachments": [
 *       {
 *         "contentType": "application/vnd.microsoft.card.adaptive",
 *         "content": { "type": "AdaptiveCard", "version": "1.4", ... }
 *       }
 *     ]
 *   }
 *
 * Adaptive Cards is the official Teams-supported card schema; the
 * older `MessageCard` format is deprecated. We keep the card minimal
 * — TextBlock header + TextBlock summary + FactSet for payload keys
 * — so it renders well in both the Teams desktop client and the
 * mobile clients.
 *
 * See {@see AbstractWebhookChannel} for the rest of the lifecycle.
 */
final class TeamsChannel extends AbstractWebhookChannel
{
    public function name(): string
    {
        return 'teams';
    }

    protected function configKey(): string
    {
        return 'askmydocs.notifications.channels.teams';
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

        $body = [
            [
                'type' => 'TextBlock',
                'text' => $title,
                'weight' => 'Bolder',
                'size' => 'Medium',
                'wrap' => true,
            ],
            [
                'type' => 'TextBlock',
                'text' => $summary,
                'wrap' => true,
            ],
        ];

        $facts = [];
        foreach (['project_key', 'slug', 'doc_id', 'change', 'actor', 'collection_slug'] as $key) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }
            $value = $payload[$key];
            if ($value === null || $value === '') {
                continue;
            }
            $facts[] = [
                'title' => str_replace('_', ' ', ucfirst($key)),
                'value' => (string) (is_scalar($value) ? $value : json_encode($value)),
            ];
        }
        if ($facts !== []) {
            $body[] = [
                'type' => 'FactSet',
                'facts' => $facts,
            ];
        }

        return [
            'type' => 'message',
            'attachments' => [
                [
                    'contentType' => 'application/vnd.microsoft.card.adaptive',
                    'content' => [
                        '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                        'type' => 'AdaptiveCard',
                        'version' => '1.4',
                        'body' => $body,
                    ],
                ],
            ],
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use App\Models\User;
use App\Notifications\Events\BaseNotificationEvent;

/**
 * v8.0/W2.1 — Discord webhook channel adapter.
 *
 * Posts to a `https://discord.com/api/webhooks/<id>/<token>` URL.
 * Discord webhooks authenticate via the URL token itself — no
 * additional signing header is required. The payload uses the
 * `embeds` format so the message renders as a rich card in the
 * target Discord channel:
 *
 *   {
 *     "embeds": [
 *       {
 *         "title": "<event subject>",
 *         "description": "<one-line summary derived from payload>",
 *         "color": <decimal RGB>,
 *         "fields": [...]
 *       }
 *     ]
 *   }
 *
 * The `color` is fixed at AskMyDocs brand purple (0x6F42C1 = 7291585)
 * so all events render with a recognisable accent. The fields are
 * derived from the event payload — we surface the most useful
 * scalar keys (`project_key`, `slug`, `doc_id`, `change`, `actor`)
 * when present and skip the rest to keep the embed compact.
 *
 * See {@see AbstractWebhookChannel} for the rest of the lifecycle.
 */
final class DiscordChannel extends AbstractWebhookChannel
{
    /**
     * AskMyDocs brand colour — encoded as the decimal RGB integer
     * Discord expects in the `color` field.
     */
    private const EMBED_COLOR = 0x6F42C1;

    public function name(): string
    {
        return 'discord';
    }

    protected function configKey(): string
    {
        return 'askmydocs.notifications.channels.discord';
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildPayload(BaseNotificationEvent $event, ?User $user): array
    {
        $eventType = $event->eventType();
        $payload = $event->payload();

        $title = NotificationSubjects::forEventType($eventType);
        $description = NotificationSummaries::forEventType($eventType, $payload);

        $fields = [];
        foreach (['project_key', 'slug', 'doc_id', 'change', 'actor', 'collection_slug'] as $key) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }
            $value = $payload[$key];
            if ($value === null || $value === '') {
                continue;
            }
            $fields[] = [
                'name' => str_replace('_', ' ', ucfirst($key)),
                'value' => (string) (is_scalar($value) ? $value : json_encode($value)),
                'inline' => true,
            ];
        }

        $embed = [
            'title' => $title,
            'description' => $description,
            'color' => self::EMBED_COLOR,
        ];
        if ($fields !== []) {
            $embed['fields'] = $fields;
        }

        return ['embeds' => [$embed]];
    }
}

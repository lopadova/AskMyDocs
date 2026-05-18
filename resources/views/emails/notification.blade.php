@php
    /** @var string $eventType */
    /** @var array<string, mixed> $payload */
    /** @var string $unsubscribeUrl */
    /** @var string|null $userName */

    // Match arms use the actual NotificationEvent::EVENT_* string
    // values (snake_case) emitted by the dispatcher. Dot-separated
    // hierarchical strings would silently fall through to default.
    $title = match ($eventType) {
        \App\Models\NotificationEvent::EVENT_KB_DOC_CREATED => 'New document published',
        \App\Models\NotificationEvent::EVENT_KB_DOC_MODIFIED => 'Document updated',
        \App\Models\NotificationEvent::EVENT_KB_CANONICAL_PROMOTED => 'Canonical decision promoted',
        \App\Models\NotificationEvent::EVENT_KB_DECISION_DEBT_THRESHOLD => 'Decision debt threshold reached',
        \App\Models\NotificationEvent::EVENT_COLLECTION_NEW_MEMBER => 'New collection member',
        default => 'AskMyDocs notification',
    };
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $title }}</title>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif; line-height: 1.5; color: #1f2937; max-width: 600px; margin: 0 auto; padding: 24px;">
    <h1 style="font-size: 18px; font-weight: 600; margin: 0 0 16px;">{{ $title }}</h1>

    @if (! empty($userName))
        <p style="margin: 0 0 12px;">Hi {{ $userName }},</p>
    @endif

    @if ($eventType === \App\Models\NotificationEvent::EVENT_KB_DOC_CREATED || $eventType === \App\Models\NotificationEvent::EVENT_KB_DOC_MODIFIED)
        <p style="margin: 0 0 12px;">
            Document
            <strong>{{ $payload['title'] ?? ($payload['source_path'] ?? 'untitled') }}</strong>
            @if (! empty($payload['project_key']))
                in project <code>{{ $payload['project_key'] }}</code>
            @endif
            was {{ ($payload['change'] ?? 'created') === 'modified' ? 'updated' : 'created' }}.
        </p>
    @elseif ($eventType === \App\Models\NotificationEvent::EVENT_KB_CANONICAL_PROMOTED)
        <p style="margin: 0 0 12px;">
            A canonical document
            @if (! empty($payload['slug']))
                <strong>{{ $payload['slug'] }}</strong>
            @endif
            in project <code>{{ $payload['project_key'] ?? '?' }}</code>
            was promoted by <em>{{ $payload['promoted_by'] ?? 'system' }}</em>.
        </p>
    @else
        <p style="margin: 0 0 12px;">
            An event of type <code>{{ $eventType }}</code> fired in your knowledge base.
        </p>
    @endif

    <hr style="border: none; border-top: 1px solid #e5e7eb; margin: 24px 0;">

    <p style="margin: 0; font-size: 12px; color: #6b7280;">
        You received this notification because you subscribed to <code>{{ $eventType }}</code>.
        <a href="{{ $unsubscribeUrl }}" style="color: #6b7280;">Unsubscribe from this event</a>.
    </p>
</body>
</html>

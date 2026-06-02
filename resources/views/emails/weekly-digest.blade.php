<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AskMyDocs weekly digest</title>
</head>
<body style="margin:0;padding:0;background:#f4f4f7;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#1a1a22;">
    <div style="max-width:600px;margin:0 auto;padding:24px;">
        <h1 style="font-size:18px;margin:0 0 4px;">Your weekly knowledge-base digest</h1>
        <p style="font-size:12px;color:#6b6b76;margin:0 0 20px;">
            Activity since {{ $weekStartDate }}
        </p>

        @forelse ($groups as $group)
            <div style="background:#ffffff;border:1px solid #e5e5ea;border-radius:10px;padding:14px 16px;margin-bottom:12px;">
                <div style="display:flex;justify-content:space-between;align-items:baseline;">
                    <strong style="font-size:14px;">{{ $group['label'] }}</strong>
                    <span style="font-size:12px;color:#6b6b76;">{{ $group['count'] }} {{ $group['count'] === 1 ? 'update' : 'updates' }}</span>
                </div>
                @if (! empty($group['samples']))
                    <ul style="margin:8px 0 0;padding-left:18px;font-size:12.5px;color:#3a3a44;">
                        @foreach ($group['samples'] as $sample)
                            <li style="margin:2px 0;">{{ $sample }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @empty
            <p style="font-size:13px;color:#6b6b76;">No activity this week.</p>
        @endforelse

        <p style="font-size:11px;color:#9a9aa3;margin-top:24px;">
            You receive this digest because email notifications are enabled on your AskMyDocs account.
            Adjust which events email you under Account → Notifications.
        </p>
    </div>
</body>
</html>

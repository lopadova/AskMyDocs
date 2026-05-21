<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Compliance Report</title>
    <style>
        body { font-family: Arial, sans-serif; color: #111; font-size: 12px; line-height: 1.4; }
        h1 { font-size: 24px; margin: 0 0 8px; }
        h2 { font-size: 16px; margin: 18px 0 8px; border-bottom: 1px solid #ddd; padding-bottom: 4px; }
        h3 { font-size: 13px; margin: 12px 0 6px; }
        .meta { margin-bottom: 12px; color: #333; }
        .chip { display: inline-block; background: #f1f5f9; border: 1px solid #e2e8f0; border-radius: 10px; padding: 2px 8px; margin-right: 6px; }
        table { width: 100%; border-collapse: collapse; margin: 6px 0 10px; }
        th, td { border: 1px solid #e5e7eb; padding: 6px; text-align: left; vertical-align: top; }
        th { background: #f8fafc; }
        .footer { margin-top: 16px; font-size: 10px; color: #555; border-top: 1px solid #ddd; padding-top: 8px; }
        pre { background: #f8fafc; border: 1px solid #e5e7eb; padding: 8px; white-space: pre-wrap; word-break: break-word; }
    </style>
</head>
<body>
<h1>Quarterly Compliance Report</h1>
<div class="meta">
    <span class="chip">Tenant: {{ $report->tenant_id }}</span>
    <span class="chip">Period: {{ $report->period_start?->toDateString() }} → {{ $report->period_end?->toDateString() }}</span>
    <span class="chip">Generated: {{ $report->generated_at?->toIso8601String() }}</span>
</div>

<h2>Index</h2>
<ol>
    <li>KB Delta</li>
    <li>Audit Aggregates</li>
    <li>Tamper-Evident Hashes</li>
</ol>

<h2>1. KB Delta</h2>
@php($delta = $report->payload_json['delta'] ?? [])
<table>
    <thead>
    <tr><th>Metric</th><th>Count</th></tr>
    </thead>
    <tbody>
    <tr><td>Added</td><td>{{ count($delta['added'] ?? []) }}</td></tr>
    <tr><td>Removed</td><td>{{ count($delta['removed'] ?? []) }}</td></tr>
    <tr><td>Superseded</td><td>{{ count($delta['superseded'] ?? []) }}</td></tr>
    <tr><td>Promoted</td><td>{{ count($delta['promoted'] ?? []) }}</td></tr>
    <tr><td>Canonical Diff Snippets</td><td>{{ count($delta['canonical_diff_snippets'] ?? []) }}</td></tr>
    </tbody>
</table>

<h3>Canonical Diff Snippets (first 5)</h3>
@foreach(array_slice($delta['canonical_diff_snippets'] ?? [], 0, 5) as $snippet)
    <pre>{{ json_encode($snippet, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
@endforeach

<h2>2. Audit Aggregates</h2>
@php($audit = $report->payload_json['audit'] ?? [])
<h3>Event Type Counts</h3>
<table>
    <thead>
    <tr><th>Event Type</th><th>Count</th></tr>
    </thead>
    <tbody>
    @foreach(($audit['event_type_counts'] ?? []) as $eventType => $count)
        <tr>
            <td>{{ $eventType }}</td>
            <td>{{ $count }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<h3>Top Actors</h3>
<table>
    <thead>
    <tr><th>Actor</th><th>Count</th></tr>
    </thead>
    <tbody>
    @foreach(($audit['top_actors'] ?? []) as $row)
        <tr>
            <td>{{ $row['actor'] ?? '' }}</td>
            <td>{{ $row['count'] ?? 0 }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<h2>3. Tamper-Evident Hashes</h2>
<table>
    <tbody>
    <tr><th>SHA-256</th><td>{{ $report->hash_sha256 }}</td></tr>
    <tr><th>HMAC SHA-256</th><td>{{ $report->hash_hmac }}</td></tr>
    </tbody>
</table>

<div class="footer">
    Report ID {{ $report->id }} · Hash footer {{ $report->hash_sha256 }}
</div>
</body>
</html>


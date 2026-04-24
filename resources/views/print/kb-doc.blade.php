<!DOCTYPE html>
<html lang="{{ $document->language ?? 'en' }}">
<head>
    <meta charset="UTF-8" />
    <meta name="robots" content="noindex,nofollow" />
    <title>{{ $document->title ?? $document->source_path }}</title>
    {{-- G2 print view: CSS @page + self-contained stylesheet so operators can print
         a canonical doc without the SPA chrome. No JS, no external deps — G4 adds
         the real PDF renderer strategy. --}}
    <style>
        @page { size: A4; margin: 1.8cm 1.6cm; }
        html, body { background: #fff; color: #111; font-family: "Helvetica Neue", Arial, sans-serif; }
        body { margin: 0; padding: 0; font-size: 12pt; line-height: 1.55; }
        header.doc-header { border-bottom: 1px solid #ccc; padding-bottom: 10px; margin-bottom: 18px; }
        h1.doc-title { font-size: 22pt; margin: 0 0 6px; letter-spacing: -0.01em; }
        .doc-meta { font-size: 9.5pt; color: #555; display: flex; flex-wrap: wrap; gap: 8px; }
        .doc-meta .pill {
            display: inline-block; padding: 1px 8px; border: 1px solid #bbb;
            border-radius: 999px; font-family: monospace; font-size: 9pt; color: #333;
        }
        .doc-body { white-space: pre-wrap; font-family: ui-monospace, "SF Mono", Menlo, monospace; font-size: 10.5pt; color: #222; }
        footer.doc-footer { margin-top: 22px; padding-top: 8px; border-top: 1px solid #ccc;
            color: #888; font-size: 8.5pt; font-family: monospace; }
        @media print {
            a { color: #111; text-decoration: none; }
            .no-print { display: none; }
        }
    </style>
</head>
<body id="doc-print">
<header class="doc-header">
    <h1 class="doc-title">{{ $document->title ?? $document->source_path }}</h1>
    <div class="doc-meta">
        <span class="pill">project: {{ $document->project_key }}</span>
        <span class="pill">path: {{ $document->source_path }}</span>
        @if($document->is_canonical)
            <span class="pill">canonical</span>
            @if($document->canonical_type)
                <span class="pill">type: {{ $document->canonical_type }}</span>
            @endif
            @if($document->canonical_status)
                <span class="pill">status: {{ $document->canonical_status }}</span>
            @endif
        @else
            <span class="pill">raw</span>
        @endif
        @if($document->indexed_at)
            <span class="pill">indexed: {{ optional($document->indexed_at)->toIso8601String() }}</span>
        @endif
    </div>
</header>

<main>
    <pre class="doc-body">{{ $body }}</pre>
</main>

<footer class="doc-footer">
    AskMyDocs · document id #{{ $document->id }} · version {{ substr($document->version_hash ?? '', 0, 12) }}
</footer>
</body>
</html>

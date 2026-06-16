@php
    /** @var array $d */
    $m = $d['metrics'] ?? [];
    $brand = '#6F42C1';
    $brand2 = '#22d3ee';
    $pct = static fn ($v) => $v === null ? '—' : round(((float) $v) * 100) . '%';
    $num = static fn ($v) => $v === null ? '—' : (is_float($v) ? round($v, 1) : $v);
    // Coverage: append '%' only when present, so a null reads "—" not "—%".
    $coverage = ($m['canonical_coverage_pct'] ?? null) === null ? '—' : $num($m['canonical_coverage_pct']) . '%';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AskMyDocs KB digest</title>
</head>
<body style="margin:0;padding:0;background:#0b0b12;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#1f1f2b;">
    <div style="max-width:640px;margin:0 auto;padding:24px;">
        <!-- header -->
        <div style="background:linear-gradient(135deg,{{ $brand }},{{ $brand2 }});border-radius:16px;padding:28px 24px;color:#fff;">
            <div style="font-size:13px;letter-spacing:.08em;text-transform:uppercase;opacity:.85;">AskMyDocs</div>
            <div style="font-size:24px;font-weight:700;margin-top:4px;">{{ ucfirst($d['frequency'] ?? 'weekly') }} knowledge digest</div>
            <div style="font-size:13px;opacity:.85;margin-top:6px;">{{ $d['period_start'] ?? '' }} &rarr; {{ $d['period_end'] ?? '' }}</div>
        </div>

        <!-- narrative -->
        @if(!empty($d['narrative']))
            <div style="background:#fff;border-radius:14px;padding:20px 22px;margin-top:16px;font-size:15px;line-height:1.55;color:#1f1f2b;border:1px solid #ece9f6;">
                {{ $d['narrative'] }}
            </div>
        @elseif(!empty($d['quiet']))
            <div style="background:#fff;border-radius:14px;padding:20px 22px;margin-top:16px;font-size:15px;line-height:1.55;color:#52525b;border:1px solid #ece9f6;">
                A quiet {{ $d['frequency'] ?? 'week' }} — no new documents, no stale reviews, and no unanswered questions. Ask your KB anything to keep it growing.
            </div>
        @endif

        <!-- KPI grid -->
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:16px;border-collapse:separate;border-spacing:10px;">
            <tr>
                @foreach([
                    ['Contributors', (int)($m['contributors'] ?? 0)],
                    ['New docs', (int)($m['new_docs'] ?? 0)],
                    ['Promoted', (int)($m['promoted_docs'] ?? 0)],
                ] as [$label, $val])
                    <td width="33%" style="background:#fff;border:1px solid #ece9f6;border-radius:12px;padding:16px;text-align:center;">
                        <div style="font-size:26px;font-weight:700;color:{{ $brand }};">{{ $val }}</div>
                        <div style="font-size:12px;color:#82829a;margin-top:2px;">{{ $label }}</div>
                    </td>
                @endforeach
            </tr>
            <tr>
                @foreach([
                    ['Answer rate', $pct($m['answer_rate'] ?? null)],
                    ['Coverage', $coverage],
                    ['Open gaps', (int)($m['open_gaps'] ?? 0)],
                ] as [$label, $val])
                    <td width="33%" style="background:#fff;border:1px solid #ece9f6;border-radius:12px;padding:16px;text-align:center;">
                        <div style="font-size:26px;font-weight:700;color:#1f1f2b;">{{ $val }}</div>
                        <div style="font-size:12px;color:#82829a;margin-top:2px;">{{ $label }}</div>
                    </td>
                @endforeach
            </tr>
        </table>

        @php
            $section = static function (string $emoji, string $title, array $rows, callable $line) {
                if ($rows === []) { return ''; }
                $html = '<div style="background:#fff;border:1px solid #ece9f6;border-radius:14px;padding:18px 20px;margin-top:16px;">';
                $html .= '<div style="font-size:15px;font-weight:700;color:#1f1f2b;margin-bottom:10px;">' . $emoji . ' ' . e($title) . '</div>';
                foreach (array_slice($rows, 0, 8) as $row) {
                    $html .= '<div style="font-size:14px;color:#3f3f52;padding:6px 0;border-top:1px solid #f4f2fb;">' . $line($row) . '</div>';
                }
                return $html . '</div>';
            };
        @endphp

        {!! $section('🆕', 'New & promoted', $d['new_docs'] ?? [], fn ($r) => e($r['title']) . ' <span style="color:#82829a;">(' . e($r['change']) . ' · ' . e($r['project_key']) . ')</span>') !!}
        {!! $section('🕓', 'Needs review', $d['stale_docs'] ?? [], fn ($r) => e($r['title']) . ' <span style="color:#82829a;">(debt ' . (int)$r['debt_score'] . ', ' . (int)$r['age_days'] . 'd untouched)</span>') !!}
        {!! $section('❓', 'Top unanswered questions', $d['top_gaps'] ?? [], fn ($r) => e($r['question']) . ' <span style="color:#82829a;">(' . (int)$r['occurrences'] . '×)</span>') !!}
        {!! $section('🏆', 'Top contributors', $d['leaderboard'] ?? [], fn ($r) => e($r['name']) . ' <span style="color:#82829a;">(' . (int)$r['score'] . ' pts)</span>') !!}

        <div style="text-align:center;color:#82829a;font-size:12px;margin-top:24px;line-height:1.6;">
            You are receiving the AskMyDocs knowledge digest.<br>
            Manage what lands here in your notification preferences.
        </div>
    </div>
</body>
</html>

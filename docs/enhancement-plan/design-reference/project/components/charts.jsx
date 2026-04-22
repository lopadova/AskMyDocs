// Sparkline + tiny charts (no deps)
const { useMemo } = React;

function Sparkline({ data, width = 120, height = 34, stroke = 'url(#spark-grad)', fill = true, showDots = false, animate = true }) {
  const path = useMemo(() => {
    if (!data || data.length === 0) return { line: '', area: '' };
    const max = Math.max(...data);
    const min = Math.min(...data);
    const range = max - min || 1;
    const stepX = width / (data.length - 1 || 1);
    const pts = data.map((v, i) => [i * stepX, height - ((v - min) / range) * (height - 6) - 3]);
    const line = pts.map((p, i) => `${i === 0 ? 'M' : 'L'} ${p[0].toFixed(1)} ${p[1].toFixed(1)}`).join(' ');
    const area = `${line} L ${width} ${height} L 0 ${height} Z`;
    return { line, area, pts };
  }, [data, width, height]);

  return (
    <svg width={width} height={height} viewBox={`0 0 ${width} ${height}`} style={{ overflow: 'visible' }}>
      <defs>
        <linearGradient id="spark-grad" x1="0" y1="0" x2="1" y2="0">
          <stop offset="0" stopColor="#8b5cf6"/>
          <stop offset="1" stopColor="#22d3ee"/>
        </linearGradient>
        <linearGradient id="spark-fill" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0" stopColor="#8b5cf6" stopOpacity="0.35"/>
          <stop offset="1" stopColor="#22d3ee" stopOpacity="0"/>
        </linearGradient>
      </defs>
      {fill && <path d={path.area} fill="url(#spark-fill)"/>}
      <path d={path.line} fill="none" stroke={stroke} strokeWidth={1.6} strokeLinecap="round" strokeLinejoin="round"
            style={animate ? { strokeDasharray: 1000, strokeDashoffset: 0, animation: 'sweep 1.2s ease-out' } : null}/>
      {showDots && path.pts && path.pts.map((p, i) => (
        <circle key={i} cx={p[0]} cy={p[1]} r={i === path.pts.length - 1 ? 3 : 0} fill="#22d3ee" stroke="var(--bg-1)" strokeWidth={1.4}/>
      ))}
    </svg>
  );
}

function AreaChart({ data, width = 520, height = 180, labels = [] }) {
  const { line, area, pts, yticks } = useMemo(() => {
    const max = Math.max(...data) * 1.1 || 1;
    const min = 0;
    const stepX = width / (data.length - 1 || 1);
    const pts = data.map((v, i) => [i * stepX, height - ((v - min) / (max - min)) * (height - 24) - 12]);
    const line = pts.map((p, i) => `${i === 0 ? 'M' : 'L'} ${p[0].toFixed(1)} ${p[1].toFixed(1)}`).join(' ');
    const area = `${line} L ${width} ${height - 4} L 0 ${height - 4} Z`;
    const yticks = [0, 0.25, 0.5, 0.75, 1].map(t => ({ y: height - 12 - t * (height - 24), v: Math.round(max * t) }));
    return { line, area, pts, yticks };
  }, [data, width, height]);

  return (
    <svg width="100%" viewBox={`0 0 ${width} ${height}`} style={{ display: 'block' }}>
      <defs>
        <linearGradient id="area-grad" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0" stopColor="#8b5cf6" stopOpacity=".5"/>
          <stop offset="1" stopColor="#22d3ee" stopOpacity="0"/>
        </linearGradient>
        <linearGradient id="area-line" x1="0" y1="0" x2="1" y2="0">
          <stop offset="0" stopColor="#8b5cf6"/>
          <stop offset="1" stopColor="#22d3ee"/>
        </linearGradient>
      </defs>
      {yticks.map((t, i) => (
        <g key={i}>
          <line x1={0} x2={width} y1={t.y} y2={t.y} stroke="var(--hairline)" strokeDasharray="2 3"/>
          <text x={0} y={t.y - 3} fontSize="10" fill="var(--fg-3)" className="mono">{t.v}</text>
        </g>
      ))}
      <path d={area} fill="url(#area-grad)"/>
      <path d={line} fill="none" stroke="url(#area-line)" strokeWidth={2}
            style={{ strokeDasharray: 2000, strokeDashoffset: 0, animation: 'sweep 1.4s ease-out' }}/>
      {pts.map((p, i) => (
        <circle key={i} cx={p[0]} cy={p[1]} r="2.5" fill="#22d3ee" opacity={i === pts.length - 1 ? 1 : 0.55}/>
      ))}
      {labels.map((l, i) => (
        <text key={i} x={(i / (labels.length - 1)) * width} y={height - 1} fontSize="10"
              fill="var(--fg-3)" textAnchor={i === 0 ? 'start' : i === labels.length - 1 ? 'end' : 'middle'} className="mono">
          {l}
        </text>
      ))}
    </svg>
  );
}

function BarStack({ data, width = 520, height = 160, labels = [] }) {
  const max = Math.max(...data.map(d => d.a + d.b + d.c)) * 1.15 || 1;
  const bw = width / data.length - 6;
  return (
    <svg width="100%" viewBox={`0 0 ${width} ${height}`} style={{ display: 'block' }}>
      <defs>
        <linearGradient id="bar-a" x1="0" x2="0" y1="0" y2="1">
          <stop offset="0" stopColor="#8b5cf6"/><stop offset="1" stopColor="#6d28d9"/>
        </linearGradient>
        <linearGradient id="bar-b" x1="0" x2="0" y1="0" y2="1">
          <stop offset="0" stopColor="#22d3ee"/><stop offset="1" stopColor="#0891b2"/>
        </linearGradient>
      </defs>
      {data.map((d, i) => {
        const total = d.a + d.b + d.c;
        const h = (total / max) * (height - 20);
        const ha = (d.a / max) * (height - 20);
        const hb = (d.b / max) * (height - 20);
        const hc = (d.c / max) * (height - 20);
        const x = i * (bw + 6) + 3;
        const y0 = height - 12;
        return (
          <g key={i} style={{ animation: `popin .4s ${i * 40}ms ease-out both` }}>
            <rect x={x} y={y0 - ha} width={bw} height={ha} fill="url(#bar-a)" rx="2"/>
            <rect x={x} y={y0 - ha - hb} width={bw} height={hb} fill="url(#bar-b)" rx="2"/>
            <rect x={x} y={y0 - ha - hb - hc} width={bw} height={hc} fill="var(--fg-3)" opacity=".5" rx="2"/>
            {labels[i] && <text x={x + bw/2} y={height - 2} fontSize="9.5" fill="var(--fg-3)" textAnchor="middle" className="mono">{labels[i]}</text>}
          </g>
        );
      })}
    </svg>
  );
}

function Donut({ segments, size = 140, stroke = 20 }) {
  const total = segments.reduce((s, v) => s + v.v, 0);
  const r = size / 2 - stroke / 2;
  const c = 2 * Math.PI * r;
  let offset = 0;
  return (
    <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`} style={{ transform: 'rotate(-90deg)' }}>
      <circle cx={size/2} cy={size/2} r={r} fill="none" stroke="var(--bg-3)" strokeWidth={stroke}/>
      {segments.map((s, i) => {
        const len = (s.v / total) * c;
        const el = (
          <circle key={i} cx={size/2} cy={size/2} r={r} fill="none"
                  stroke={s.color} strokeWidth={stroke} strokeLinecap="round"
                  strokeDasharray={`${len} ${c}`} strokeDashoffset={-offset}
                  style={{ transition: 'stroke-dashoffset .6s' }}/>
        );
        offset += len;
        return el;
      })}
    </svg>
  );
}

Object.assign(window, { Sparkline, AreaChart, BarStack, Donut });

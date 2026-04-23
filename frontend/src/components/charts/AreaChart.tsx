import { useMemo } from 'react';

export type AreaChartProps = {
    data: number[];
    width?: number;
    height?: number;
    labels?: string[];
};

export function AreaChart({ data, width = 520, height = 180, labels = [] }: AreaChartProps) {
    const { line, area, pts, yticks } = useMemo(() => {
        const max = Math.max(...data) * 1.1 || 1;
        const min = 0;
        const stepX = width / (data.length - 1 || 1);
        const p: [number, number][] = data.map((v, i) => [
            i * stepX,
            height - ((v - min) / (max - min)) * (height - 24) - 12,
        ]);
        const l = p
            .map((pt, i) => `${i === 0 ? 'M' : 'L'} ${pt[0].toFixed(1)} ${pt[1].toFixed(1)}`)
            .join(' ');
        const a = `${l} L ${width} ${height - 4} L 0 ${height - 4} Z`;
        const yt = [0, 0.25, 0.5, 0.75, 1].map((t) => ({
            y: height - 12 - t * (height - 24),
            v: Math.round(max * t),
        }));
        return { line: l, area: a, pts: p, yticks: yt };
    }, [data, width, height]);

    return (
        <svg width="100%" viewBox={`0 0 ${width} ${height}`} style={{ display: 'block' }}>
            <defs>
                <linearGradient id="area-grad" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0" stopColor="#8b5cf6" stopOpacity=".5" />
                    <stop offset="1" stopColor="#22d3ee" stopOpacity="0" />
                </linearGradient>
                <linearGradient id="area-line" x1="0" y1="0" x2="1" y2="0">
                    <stop offset="0" stopColor="#8b5cf6" />
                    <stop offset="1" stopColor="#22d3ee" />
                </linearGradient>
            </defs>
            {yticks.map((t, i) => (
                <g key={i}>
                    <line
                        x1={0}
                        x2={width}
                        y1={t.y}
                        y2={t.y}
                        stroke="var(--hairline)"
                        strokeDasharray="2 3"
                    />
                    <text x={0} y={t.y - 3} fontSize="10" fill="var(--fg-3)" className="mono">
                        {t.v}
                    </text>
                </g>
            ))}
            <path d={area} fill="url(#area-grad)" />
            <path
                d={line}
                fill="none"
                stroke="url(#area-line)"
                strokeWidth={2}
                style={{ strokeDasharray: 2000, strokeDashoffset: 0, animation: 'sweep 1.4s ease-out' }}
            />
            {pts.map((p, i) => (
                <circle
                    key={i}
                    cx={p[0]}
                    cy={p[1]}
                    r="2.5"
                    fill="#22d3ee"
                    opacity={i === pts.length - 1 ? 1 : 0.55}
                />
            ))}
            {labels.map((l, i) => (
                <text
                    key={i}
                    x={(i / (labels.length - 1)) * width}
                    y={height - 1}
                    fontSize="10"
                    fill="var(--fg-3)"
                    textAnchor={i === 0 ? 'start' : i === labels.length - 1 ? 'end' : 'middle'}
                    className="mono"
                >
                    {l}
                </text>
            ))}
        </svg>
    );
}

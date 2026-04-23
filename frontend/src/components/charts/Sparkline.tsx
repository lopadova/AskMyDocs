import { useMemo } from 'react';

export type SparklineProps = {
    data: number[];
    width?: number;
    height?: number;
    stroke?: string;
    fill?: boolean;
    showDots?: boolean;
    animate?: boolean;
};

/*
 * DIY SVG sparkline from the design reference (`design-reference/project/
 * components/charts.jsx`). No chart library dependency; rich-content /
 * dashboard charts will switch to recharts in PR6-F1 if interactivity
 * demands it.
 */
export function Sparkline({
    data,
    width = 120,
    height = 34,
    stroke = 'url(#spark-grad)',
    fill = true,
    showDots = false,
    animate = true,
}: SparklineProps) {
    const path = useMemo(() => {
        if (!data || data.length === 0) {
            return { line: '', area: '', pts: [] as [number, number][] };
        }
        const max = Math.max(...data);
        const min = Math.min(...data);
        const range = max - min || 1;
        const stepX = width / (data.length - 1 || 1);
        const pts: [number, number][] = data.map((v, i) => [
            i * stepX,
            height - ((v - min) / range) * (height - 6) - 3,
        ]);
        const line = pts
            .map((p, i) => `${i === 0 ? 'M' : 'L'} ${p[0].toFixed(1)} ${p[1].toFixed(1)}`)
            .join(' ');
        const area = `${line} L ${width} ${height} L 0 ${height} Z`;
        return { line, area, pts };
    }, [data, width, height]);

    return (
        <svg
            width={width}
            height={height}
            viewBox={`0 0 ${width} ${height}`}
            style={{ overflow: 'visible' }}
        >
            <defs>
                <linearGradient id="spark-grad" x1="0" y1="0" x2="1" y2="0">
                    <stop offset="0" stopColor="#8b5cf6" />
                    <stop offset="1" stopColor="#22d3ee" />
                </linearGradient>
                <linearGradient id="spark-fill" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0" stopColor="#8b5cf6" stopOpacity="0.35" />
                    <stop offset="1" stopColor="#22d3ee" stopOpacity="0" />
                </linearGradient>
            </defs>
            {fill && <path d={path.area} fill="url(#spark-fill)" />}
            <path
                d={path.line}
                fill="none"
                stroke={stroke}
                strokeWidth={1.6}
                strokeLinecap="round"
                strokeLinejoin="round"
                style={
                    animate
                        ? {
                              strokeDasharray: 1000,
                              strokeDashoffset: 0,
                              animation: 'sweep 1.2s ease-out',
                          }
                        : undefined
                }
            />
            {showDots &&
                path.pts.map((p, i) => (
                    <circle
                        key={i}
                        cx={p[0]}
                        cy={p[1]}
                        r={i === path.pts.length - 1 ? 3 : 0}
                        fill="#22d3ee"
                        stroke="var(--bg-1)"
                        strokeWidth={1.4}
                    />
                ))}
        </svg>
    );
}

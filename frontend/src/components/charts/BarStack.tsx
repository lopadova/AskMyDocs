export type BarDatum = { a: number; b: number; c: number };

export type BarStackProps = {
    data: BarDatum[];
    width?: number;
    height?: number;
    labels?: string[];
};

export function BarStack({ data, width = 520, height = 160, labels = [] }: BarStackProps) {
    // Empty-data guard: without this, `Math.max(...[])` returns -Infinity
    // and `width / data.length - 6` returns Infinity, producing invalid
    // SVG coordinates. Render an empty placeholder instead.
    if (data.length === 0) {
        return (
            <svg
                width="100%"
                viewBox={`0 0 ${width} ${height}`}
                style={{ display: 'block' }}
                role="img"
                aria-label="No data"
                data-testid="bar-stack-empty"
            />
        );
    }

    const max = Math.max(...data.map((d) => d.a + d.b + d.c)) * 1.15 || 1;
    const bw = width / data.length - 6;
    return (
        <svg width="100%" viewBox={`0 0 ${width} ${height}`} style={{ display: 'block' }}>
            <defs>
                <linearGradient id="bar-a" x1="0" x2="0" y1="0" y2="1">
                    <stop offset="0" stopColor="#8b5cf6" />
                    <stop offset="1" stopColor="#6d28d9" />
                </linearGradient>
                <linearGradient id="bar-b" x1="0" x2="0" y1="0" y2="1">
                    <stop offset="0" stopColor="#22d3ee" />
                    <stop offset="1" stopColor="#0891b2" />
                </linearGradient>
            </defs>
            {data.map((d, i) => {
                const ha = (d.a / max) * (height - 20);
                const hb = (d.b / max) * (height - 20);
                const hc = (d.c / max) * (height - 20);
                const x = i * (bw + 6) + 3;
                const y0 = height - 12;
                return (
                    <g key={i} style={{ animation: `popin .4s ${i * 40}ms ease-out both` }}>
                        <rect x={x} y={y0 - ha} width={bw} height={ha} fill="url(#bar-a)" rx="2" />
                        <rect x={x} y={y0 - ha - hb} width={bw} height={hb} fill="url(#bar-b)" rx="2" />
                        <rect
                            x={x}
                            y={y0 - ha - hb - hc}
                            width={bw}
                            height={hc}
                            fill="var(--fg-3)"
                            opacity=".5"
                            rx="2"
                        />
                        {labels[i] && (
                            <text
                                x={x + bw / 2}
                                y={height - 2}
                                fontSize="9.5"
                                fill="var(--fg-3)"
                                textAnchor="middle"
                                className="mono"
                            >
                                {labels[i]}
                            </text>
                        )}
                    </g>
                );
            })}
        </svg>
    );
}

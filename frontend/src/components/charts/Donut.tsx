export type DonutSegment = { v: number; color: string; label?: string };

export type DonutProps = {
    segments: DonutSegment[];
    size?: number;
    stroke?: number;
};

export function Donut({ segments, size = 140, stroke = 20 }: DonutProps) {
    const total = segments.reduce((s, v) => s + v.v, 0) || 1;
    const r = size / 2 - stroke / 2;
    const c = 2 * Math.PI * r;
    let offset = 0;
    return (
        <svg
            width={size}
            height={size}
            viewBox={`0 0 ${size} ${size}`}
            style={{ transform: 'rotate(-90deg)' }}
        >
            <circle
                cx={size / 2}
                cy={size / 2}
                r={r}
                fill="none"
                stroke="var(--bg-3)"
                strokeWidth={stroke}
            />
            {segments.map((s, i) => {
                const len = (s.v / total) * c;
                const el = (
                    <circle
                        key={i}
                        cx={size / 2}
                        cy={size / 2}
                        r={r}
                        fill="none"
                        stroke={s.color}
                        strokeWidth={stroke}
                        strokeLinecap="round"
                        strokeDasharray={`${len} ${c}`}
                        strokeDashoffset={-offset}
                        style={{ transition: 'stroke-dashoffset .6s' }}
                    />
                );
                offset += len;
                return el;
            })}
        </svg>
    );
}

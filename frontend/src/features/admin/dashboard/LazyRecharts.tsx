import { lazy } from 'react';
import type { AdminChatVolumeRow, AdminTokenBurnRow, AdminRatingDistribution } from '../admin.api';

/*
 * Recharts is heavy (~100 kB gz). We code-split each chart as a single
 * lazy module so the dashboard's critical JS stays small — none of
 * recharts loads unless the view is rendered. Each lazy import is its
 * own chunk, so a user who only looks at KPIs without scrolling never
 * pulls the pie/bar/area branches either.
 *
 * The bodies are implemented inside the lazy() factory so the import
 * is isolated to the chunk. Consumers import the named Lazy*Body
 * component and wrap it in a <Suspense fallback={<EmptyFallback/>}>.
 */

export interface AreaChartBodyProps {
    data: AdminChatVolumeRow[];
}

export interface BarChartBodyProps {
    data: AdminTokenBurnRow[];
}

export interface DonutBodyProps {
    distribution: AdminRatingDistribution;
}

export const LazyAreaChartBody = lazy(async () => {
    const {
        ResponsiveContainer,
        AreaChart,
        Area,
        XAxis,
        YAxis,
        CartesianGrid,
        Tooltip,
    } = await import('recharts');

    const Body = ({ data }: AreaChartBodyProps) => (
        <ResponsiveContainer width="100%" height={220}>
            <AreaChart data={data} margin={{ top: 10, right: 10, bottom: 0, left: 0 }}>
                <defs>
                    <linearGradient id="chatVolumeFill" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="5%" stopColor="#8b5cf6" stopOpacity={0.55} />
                        <stop offset="95%" stopColor="#22d3ee" stopOpacity={0.05} />
                    </linearGradient>
                </defs>
                <CartesianGrid stroke="var(--hairline)" strokeDasharray="2 4" vertical={false} />
                <XAxis dataKey="date" tick={{ fill: 'var(--fg-3)', fontSize: 10 }} />
                <YAxis tick={{ fill: 'var(--fg-3)', fontSize: 10 }} allowDecimals={false} />
                <Tooltip
                    contentStyle={{
                        background: 'var(--bg-1)',
                        border: '1px solid var(--hairline)',
                        fontSize: 11,
                    }}
                />
                <Area
                    type="monotone"
                    dataKey="count"
                    stroke="#8b5cf6"
                    strokeWidth={2}
                    fill="url(#chatVolumeFill)"
                    isAnimationActive={false}
                />
            </AreaChart>
        </ResponsiveContainer>
    );
    return { default: Body };
});

export const LazyBarChartBody = lazy(async () => {
    const {
        ResponsiveContainer,
        BarChart,
        Bar,
        XAxis,
        YAxis,
        CartesianGrid,
        Tooltip,
        Legend,
    } = await import('recharts');

    const Body = ({ data }: BarChartBodyProps) => (
        <ResponsiveContainer width="100%" height={220}>
            <BarChart data={data} margin={{ top: 10, right: 10, bottom: 0, left: 0 }}>
                <CartesianGrid stroke="var(--hairline)" strokeDasharray="2 4" vertical={false} />
                <XAxis dataKey="provider" tick={{ fill: 'var(--fg-3)', fontSize: 10 }} />
                <YAxis tick={{ fill: 'var(--fg-3)', fontSize: 10 }} />
                <Tooltip
                    contentStyle={{
                        background: 'var(--bg-1)',
                        border: '1px solid var(--hairline)',
                        fontSize: 11,
                    }}
                />
                <Legend wrapperStyle={{ fontSize: 11 }} />
                <Bar dataKey="prompt_tokens" stackId="a" fill="#8b5cf6" />
                <Bar dataKey="completion_tokens" stackId="a" fill="#22d3ee" />
            </BarChart>
        </ResponsiveContainer>
    );
    return { default: Body };
});

export const LazyDonutBody = lazy(async () => {
    const { ResponsiveContainer, PieChart, Pie, Cell, Tooltip, Legend } = await import('recharts');

    const Body = ({ distribution }: DonutBodyProps) => {
        const data = [
            { name: 'Positive', value: distribution.positive, fill: '#86efac' },
            { name: 'Negative', value: distribution.negative, fill: '#fca5a5' },
            { name: 'Unrated', value: distribution.unrated, fill: '#64748b' },
        ];
        return (
            <ResponsiveContainer width="100%" height={220}>
                <PieChart>
                    <Pie
                        data={data}
                        innerRadius={50}
                        outerRadius={80}
                        paddingAngle={2}
                        dataKey="value"
                        isAnimationActive={false}
                    >
                        {data.map((entry) => (
                            <Cell key={entry.name} fill={entry.fill} />
                        ))}
                    </Pie>
                    <Tooltip
                        contentStyle={{
                            background: 'var(--bg-1)',
                            border: '1px solid var(--hairline)',
                            fontSize: 11,
                        }}
                    />
                    <Legend wrapperStyle={{ fontSize: 11 }} />
                </PieChart>
            </ResponsiveContainer>
        );
    };
    return { default: Body };
});

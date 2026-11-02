import { Area, AreaChart, Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { useUiTranslation } from '../../../lib/ui-locale';
import { amount, count } from './usage-format';

export type ChartSeries = { key: string; label: string; color: string };
export type ChartPoint = { date: string; end: string; [key: string]: string | number | null };
type Props = { title: string; subtitle?: string; points: ChartPoint[]; series: ChartSeries[]; currency?: string; unit?: string; area?: boolean };

export default function ActivityChart({ title, subtitle, points, series, currency, unit = 'Entries', area = false }: Props) {
    const { t, locale } = useUiTranslation();
    const format = (n: number) => currency ? amount(n, locale, currency) : count(n, locale);
    const date = (s: string) => new Intl.DateTimeFormat(locale, { day: 'numeric', month: 'short', timeZone: 'UTC' }).format(new Date(s + 'T12:00:00Z'));
    const grid = <CartesianGrid vertical={false} stroke="var(--usage-grid)" strokeDasharray="3 5" />;
    const tickStep = Math.max(1, Math.ceil((points.length - 1) / 6));
    const ticks = points.filter((_, i) => i === points.length - 1 || i % tickStep === 0).map(p => p.date);
    const x = <XAxis dataKey="date" ticks={ticks} interval="preserveStartEnd" axisLine={false} tickLine={false} minTickGap={22} tickFormatter={date} tick={{ fill: 'var(--fg-3)', fontSize: 11 }} />;
    const y = <YAxis width={70} domain={[(min: number) => Math.min(0, min), (max: number) => Math.max(0, max)]} axisLine={false} tickLine={false} allowDecimals={Boolean(currency)} tick={{ fill: 'var(--fg-3)', fontSize: 10 }}
        tickFormatter={(v: number) => currency ? new Intl.NumberFormat(locale, { maximumFractionDigits: Math.abs(v) < .01 ? 10 : 3 }).format(v) : new Intl.NumberFormat(locale, { notation: 'compact' }).format(v)} />;
    const tooltip = <Tooltip cursor={{ fill: 'var(--usage-hover)' }} content={({ active, payload }) => {
        if (!active || !payload?.length) return null;
        const point = payload[0].payload as ChartPoint;
        return <div className="usage-tooltip"><strong>{date(point.date)}{point.end !== point.date ? ` - ${date(point.end)}` : ''}</strong>
            {series.map(s => <div key={s.key}><span><i style={{ background: s.color }} />{s.label}</span><b>{point[s.key] == null ? t('Not available') : format(Number(point[s.key]))}</b></div>)}</div>;
    }} />;
    return <section className="usage-card usage-chart" aria-label={t(title)}>
        <div className="usage-card-heading"><h2>{t(title)}</h2><span>{currency ?? t(unit)}</span></div>
        {subtitle && <p className="usage-chart-subtitle">{t(subtitle)}</p>}
        <div className="usage-plot" role="group" aria-label={t(title)}>
            <ResponsiveContainer width="100%" height={235}>
                {area ? <AreaChart data={points} accessibilityLayer margin={{ top: 12, right: 12, bottom: 2, left: 0 }}>
                    {grid}{x}{y}{tooltip}
                    {series.map(s => <Area key={s.key} type="monotone" dataKey={s.key} name={s.label} stroke={s.color} fill={s.color} fillOpacity={.15} strokeWidth={2} dot={points.length === 1 ? { r: 3 } : false} isAnimationActive={false} />)}
                </AreaChart> : <BarChart data={points} accessibilityLayer stackOffset="sign" margin={{ top: 12, right: 12, bottom: 2, left: 0 }}>
                    {grid}{x}{y}{tooltip}
                    {series.map(s => <Bar key={s.key} dataKey={s.key} name={s.label} stackId="usage" fill={s.color} maxBarSize={32} isAnimationActive={false} />)}
                </BarChart>}
            </ResponsiveContainer>
        </div>
        <div className="usage-legend">{series.map(s => <span key={s.key} title={s.label}><i style={{ background: s.color }} />{s.label}</span>)}</div>
        <details className="usage-chart-data"><summary>{t('Show chart data')}</summary><div className="usage-table-scroll"><table>
            <thead><tr><th>{t('Period')}</th>{series.map(s => <th key={s.key}>{s.label}</th>)}</tr></thead>
            <tbody>{points.map(p => <tr key={p.date}><td>{p.date}{p.end !== p.date ? ` - ${p.end}` : ''}</td>{series.map(s => <td key={s.key}>{p[s.key] == null ? t('Not available') : format(Number(p[s.key]))}</td>)}</tr>)}</tbody>
        </table></div></details>
    </section>;
}

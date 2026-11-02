import { lazy, Suspense, useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Button } from '../../../components/Button';
import { Icon } from '../../../components/Icons';
import { Sparkline } from '../../../components/charts';
import { useUiTranslation } from '../../../lib/ui-locale';
import { useTeamStore } from '../../../lib/team-store';
import { AdminShell } from '../shell/AdminShell';
import { aiUsageApi, type UsageActivity, type UsageFilters, type UsageMetrics } from './ai-usage.api';
import { amount, count, initialDates, seriesColor } from './usage-format';
import type { ChartPoint, ChartSeries } from './ActivityCharts';
import './ai-usage.css';

const ActivityChart = lazy(() => import('./ActivityCharts'));
const statuses = ['recorded', 'covered', 'failed', 'estimated', 'blocked'];
const statusLabel: Record<string, string> = { recorded: 'Recorded', covered: 'Subscription covered', failed: 'Failed', estimated: 'Pre-flight estimates', blocked: 'Blocked' };

export function AiUsageView() {
    const { t, locale } = useUiTranslation();
    const tenant = useTeamStore(s => s.currentTeam);
    const [filters, setFilters] = useState<UsageFilters>(() => ({ ...initialDates(), timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC', provider: '', purpose: '', status: '', currency: '' }));
    const [dates, setDates] = useState({ from: filters.from, to: filters.to });
    const [tab, setTab] = useState<'overview' | 'models'>('overview');
    const [search, setSearch] = useState(''); const [page, setPage] = useState(0);
    const query = useQuery({ queryKey: ['admin', 'ai-usage', tenant, filters],
        queryFn: ({ signal }) => aiUsageApi.activity(filters, signal), staleTime: 30_000, refetchOnWindowFocus: false });
    const data = query.data; const ready = data?.status === 'available';
    const change = (key: keyof UsageFilters, value: string) => { setFilters(f => ({ ...f, [key]: value })); setPage(0); };
    const money = (value: number) => amount(value, locale, data?.currency ?? 'USD');
    const noValue = t('Not available');
    const priced = data?.summary?.entries === 0 || (data?.summary && data.summary.unpriced_entries < data.summary.entries);
    const rowCost = (m: UsageMetrics) => m.entries > 0 && m.unpriced_entries === m.entries ? noValue : money(m.cost);
    const models = useMemo(() => (data?.models ?? []).filter(m => `${m.model} ${m.provider}`.toLowerCase().includes(search.toLowerCase())), [data, search]);
    const currencies = [...new Set([...(data?.options.currencies ?? []), filters.currency || data?.currency || 'USD'])];
    const state = query.isError ? 'error' : query.isLoading ? 'loading' : ready ? data.summary?.entries ? 'ready' : 'empty' : 'unavailable';

    return <AdminShell section="ai-usage"><div className="ai-usage" data-testid="admin-ai-usage" data-state={state} aria-busy={query.isFetching}>
        <header className="usage-header"><div><h1>{t('AI usage')}</h1><p>{t('Tokens, recorded costs and models used by this tenant.')}</p></div>
            <form className="usage-period" onSubmit={e => { e.preventDefault(); setFilters(f => ({ ...f, ...dates })); setPage(0); }}>
                <label>{t('Period')}<select aria-label={t('Quick period')} value={filters.from === initialDates(7).from && filters.to === initialDates(7).to ? '7' : filters.from === initialDates(30).from && filters.to === initialDates(30).to ? '30' : 'custom'}
                    onChange={e => { if (e.target.value === 'custom') return; const next = initialDates(Number(e.target.value)); setDates(next); setFilters(f => ({ ...f, ...next })); }}>
                    <option value="7">{t('Last 7 days')}</option><option value="30">{t('Last 30 days')}</option><option value="custom">{t('Custom period')}</option>
                </select></label>
                <label>{t('From')}<input type="date" value={dates.from} max={dates.to} required onChange={e => setDates(d => ({ ...d, from: e.target.value }))} /></label>
                <label>{t('To')}<input type="date" value={dates.to} min={dates.from} required onChange={e => setDates(d => ({ ...d, to: e.target.value }))} /></label>
                <Button type="submit" size="sm" busy={query.isFetching} leadingIcon={<Icon.Calendar size={14} />}>{t('Apply')}</Button>
            </form>
        </header>
        <div className="usage-filters">
            <label>{t('Provider')}<select value={filters.provider} onChange={e => change('provider', e.target.value)}><option value="">{t('All providers')}</option>{data?.options.providers.map(p => <option key={p} value={p}>{p}</option>)}</select></label>
            <label>{t('Purpose')}<select value={filters.purpose} onChange={e => change('purpose', e.target.value)}><option value="">{t('All purposes')}</option>{data?.options.purposes.map(p => <option key={p} value={p}>{p}</option>)}</select></label>
            <label>{t('Status')}<select value={filters.status} onChange={e => change('status', e.target.value)}><option value="">{t('Incurred usage')}</option>{statuses.map(s => <option key={s} value={s}>{t(statusLabel[s])}</option>)}</select></label>
            <label>{t('Currency')}<select value={filters.currency || data?.currency || 'USD'} onChange={e => change('currency', e.target.value)}>{currencies.map(c => <option key={c} value={c}>{c}</option>)}</select></label>
            <span className="usage-timezone"><Icon.Globe size={13} />{filters.timezone}</span>
            <Button size="sm" variant="quiet" onClick={() => void query.refetch()} busy={query.isFetching} leadingIcon={<Icon.Refresh size={14} />}>{t('Refresh')}</Button>
        </div>
        <div className="usage-tabs" role="tablist" aria-label={t('AI usage sections')} onKeyDown={e => {
            if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(e.key)) return;
            e.preventDefault();
            const next = e.key === 'Home' ? 'overview' : e.key === 'End' ? 'models' : tab === 'overview' ? 'models' : 'overview';
            setTab(next); document.getElementById(`usage-${next}-tab`)?.focus();
        }}>
            <Button variant="quiet" role="tab" tabIndex={tab === 'overview' ? 0 : -1} aria-selected={tab === 'overview'} aria-controls="usage-overview" id="usage-overview-tab" onClick={() => setTab('overview')}>{t('Overview')}</Button>
            <Button variant="quiet" role="tab" tabIndex={tab === 'models' ? 0 : -1} aria-selected={tab === 'models'} aria-controls="usage-models" id="usage-models-tab" onClick={() => setTab('models')}>{t('Models')}</Button>
        </div>
        {query.isError && <div className="usage-notice" role="alert">{t('Usage could not be loaded. Retry or choose a shorter period.')}</div>}
        {query.isLoading && <div className="usage-loading" role="status">{t('Loading usage…')}</div>}
        {data?.status === 'unavailable' && <div className="usage-notice" role="status">{t(data.reason === 'interval_too_broad' ? 'Choose a shorter period or filter by provider to explore this activity.' : 'The usage ledger is unavailable. Costs and tokens cannot be calculated right now.')}</div>}
        {ready && data.summary && <>
            {!data.metering_enabled && <div className="usage-notice">{t('Usage metering is disabled. These are historical records; new activity may be missing.')}</div>}
            {(filters.status === 'estimated' || filters.status === 'blocked') && <div className="usage-notice">{t('This view includes pre-flight or blocked records, not incurred spend.')}</div>}
            {data.summary.unpriced_entries > 0 && <div className="usage-notice">{count(data.summary.unpriced_entries, locale)} {t('entries have no verifiable price. Recorded spend is partial; zero does not mean free.')}</div>}
            {data.summary.estimated_entries > 0 && <p className="usage-source-note">{count(data.summary.estimated_entries, locale)} {t('entries use estimated tokens or costs.')}</p>}
            <div className="usage-kpis">{([
                ['Recorded spend', priced ? money(data.summary.cost) : noValue, 'Costs in the selected currency', data.summary.unpriced_entries ? [] : data.buckets.map(b => b.cost)],
                ['Total tokens', count(data.summary.tokens, locale), 'Input plus output; subsets are not added', data.buckets.map(b => b.tokens)],
                ['Ledger entries', count(data.summary.entries, locale), 'Recorded entries, not user questions', data.buckets.map(b => b.entries)],
                ['Cost per 1M tokens', data.summary.cost_per_million === null ? noValue : money(data.summary.cost_per_million), 'Token costs and matching token volume only', []],
                ['Average latency', data.summary.average_latency_ms === null ? noValue : `${count(data.summary.average_latency_ms, locale)} ms`, 'Only entries with a measured duration', []],
            ] as [string, string, string, number[]][]).map(([label, value, hint, points]) => <section className="usage-card usage-kpi" key={label}><div><h2>{t(label)}</h2>{points.length > 1 && value !== noValue && <span aria-hidden="true"><Sparkline data={points} width={65} height={28} stroke="#22a77a" fill={false} animate={false} /></span>}</div><strong title={value}>{value}</strong><p>{t(hint)}</p></section>)}</div>
            {tab === 'overview' && <section role="tabpanel" id="usage-overview" aria-labelledby="usage-overview-tab">
                {data.summary.entries === 0 ? <div className="usage-card usage-empty">{t('No recorded usage matches this period and these filters.')}</div> : <>
                    <div className="usage-two"><Ranking title={t('Top models')} rows={data.top_models.map(m => ({ id: m.id, name: m.model, detail: m.provider, value: data.ranking_metric === 'tokens' ? `${count(m.tokens, locale)} ${t('Tokens')}` : rowCost(m) }))} />
                        <Ranking title={t('Top providers')} rows={data.top_providers.map(p => ({ id: p.provider, name: p.provider, detail: `${count(p.entries, locale)} ${t('entries')}`, value: data.ranking_metric === 'tokens' ? `${count(p.tokens, locale)} ${t('Tokens')}` : rowCost(p) }))} /></div>
                    <Suspense fallback={<div className="usage-loading" role="status">{t('Loading charts…')}</div>}><Charts key={`${JSON.stringify(filters)}:${query.dataUpdatedAt}`} data={data} /></Suspense>
                </>}
            </section>}
            {tab === 'models' && <section role="tabpanel" className="usage-card usage-models" id="usage-models" aria-labelledby="usage-models-tab">
                <div className="usage-card-heading"><h2>{t('Model details')}</h2><label>{t('Search models')}<input type="search" value={search} onChange={e => { setSearch(e.target.value); setPage(0); }} /></label></div>
                <div className="usage-table-scroll"><table><thead><tr>{['Model', 'Provider', 'Recorded spend', 'Input tokens', 'Output tokens', 'Ledger entries'].map(h => <th key={h}>{t(h)}</th>)}</tr></thead>
                    <tbody>{models.slice(page * 20, (page + 1) * 20).map(m => <tr key={m.id}><td title={m.model}>{m.model}</td><td>{m.provider}</td><td>{rowCost(m)}</td><td>{count(m.tokens_input, locale)}</td><td>{count(m.tokens_output, locale)}</td><td>{count(m.entries, locale)}</td></tr>)}</tbody></table></div>
                {models.length === 0 && <p className="usage-empty">{t('No models match your search.')}</p>}
                <div className="usage-pagination"><span>{count(models.length, locale)} {t('models')}</span><Button size="sm" disabled={page === 0} onClick={() => setPage(p => p - 1)}>{t('Previous')}</Button><Button size="sm" disabled={(page + 1) * 20 >= models.length} onClick={() => setPage(p => p + 1)}>{t('Next')}</Button></div>
            </section>}
            <p className="usage-source-note">{t('Source: tenant usage ledger. Currencies are kept separate; costs may be provider-reported or calculated from tariffs. Cache is included in input and reasoning in output.')}</p>
        </>}
    </div></AdminShell>;
}

function Ranking({ title, rows }: { title: string; rows: { id: string; name: string; detail: string; value: string }[] }) {
    return <section className="usage-card usage-ranking"><h2>{title}</h2><ol>{rows.map((r, i) => <li key={r.id}><span className="usage-rank">{i + 1}</span><i style={{ background: seriesColor(r.id) }} /><div><strong title={r.name}>{r.name}</strong><span>{r.detail}</span></div><b>{r.value}</b></li>)}</ol></section>;
}

function Charts({ data }: { data: UsageActivity }) {
    const { t } = useUiTranslation();
    const modelSeries: ChartSeries[] = data.series.map((s, i) => ({ key: `s${i}`, label: s.other ? t('Other models') : `${s.name} · ${s.provider}`, color: seriesColor(s.id) }));
    const observed = (m: UsageMetrics, key: keyof UsageMetrics) => key === 'cost' && m.entries > 0 && m.unpriced_entries === m.entries ? null : m[key] ?? 0;
    const points = (metric: keyof UsageMetrics): ChartPoint[] => data.buckets.map((b, index) => ({ date: b.from, end: b.to, ...Object.fromEntries(data.series.map((s, i) => [`s${i}`, observed(s.values[index], metric)])) }));
    const single = (keys: (keyof UsageMetrics)[]): ChartPoint[] => data.buckets.map(b => ({ date: b.from, end: b.to, ...Object.fromEntries(keys.map(k => [k, observed(b, k)])) }));
    const tokensOnly = data.ranking_metric === 'tokens';
    return <div className="usage-charts"><ActivityChart title={tokensOnly ? 'Tokens by model' : 'Cost by model'} points={points(tokensOnly ? 'tokens' : 'cost')} series={modelSeries} currency={tokensOnly ? undefined : data.currency} unit="Tokens" />
        <div className="usage-two"><ActivityChart title={tokensOnly ? 'Entries over time' : 'Spend over time'} points={single([tokensOnly ? 'entries' : 'cost'])} series={[{ key: tokensOnly ? 'entries' : 'cost', label: t(tokensOnly ? 'Ledger entries' : 'Recorded spend'), color: '#8b5cf6' }]} currency={tokensOnly ? undefined : data.currency} area />
            <ActivityChart title="Volume by model" points={points('entries')} series={modelSeries} />
            <ActivityChart title="Token composition" subtitle="Input plus output; subsets are not added" unit="Tokens" points={single(['tokens_input', 'tokens_output'])} series={[{ key: 'tokens_input', label: t('Input tokens'), color: '#3485ff' }, { key: 'tokens_output', label: t('Output tokens'), color: '#8b5cf6' }]} />
            <ActivityChart title="Token details" subtitle="Cache and reasoning are already included in total tokens" unit="Tokens" points={single(['tokens_cached', 'tokens_reasoning'])} series={[{ key: 'tokens_cached', label: t('Cached tokens'), color: '#06b6a0' }, { key: 'tokens_reasoning', label: t('Reasoning tokens'), color: '#d969d9' }]} /></div>
    </div>;
}

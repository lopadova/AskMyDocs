// Admin Dashboard — KPI, sparklines, health strip, charts, activity feed
const { useState: uSd, useEffect: uEd, useMemo: uMd } = React;

function KpiCard({ label, value, delta, sub, icon, series, positive }) {
  const Ico = Icon[icon];
  return (
    <div className="panel popin" style={{ padding: 16, display: 'flex', flexDirection: 'column', gap: 10, overflow: 'hidden', position: 'relative' }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
        <div style={{ width: 28, height: 28, borderRadius: 7, background: 'var(--grad-accent-soft)', border: '1px solid var(--panel-border)', display: 'flex', alignItems: 'center', justifyContent: 'center', color: 'var(--fg-0)' }}>
          <Ico size={14}/>
        </div>
        <div style={{ fontSize: 11.5, color: 'var(--fg-2)', fontFamily: 'var(--font-mono)', textTransform: 'uppercase', letterSpacing: '.06em' }}>{label}</div>
        <span style={{ flex: 1 }}/>
        <span className={`pill ${positive ? 'ok' : 'err'}`} style={{ fontSize: 10.5 }}>{delta}</span>
      </div>
      <div style={{ display: 'flex', alignItems: 'flex-end', gap: 12, justifyContent: 'space-between' }}>
        <div>
          <div style={{ fontSize: 28, fontWeight: 600, letterSpacing: '-0.02em', color: 'var(--fg-0)', lineHeight: 1, fontFamily: 'var(--font-sans)' }}>{value}</div>
          <div style={{ fontSize: 11, color: 'var(--fg-3)', marginTop: 4, fontFamily: 'var(--font-mono)' }}>{sub}</div>
        </div>
        <Sparkline data={series} width={110} height={36}/>
      </div>
    </div>
  );
}

function HealthStrip() {
  const checks = [
    { id: 'db',        label: 'PostgreSQL',       status: 'ok',   lat: '4ms' },
    { id: 'pgv',       label: 'pgvector',         status: 'ok',   lat: '12ms' },
    { id: 'queue',     label: 'Queue (redis)',    status: 'ok',   lat: '2ms' },
    { id: 'disk',      label: 'KB disk',          status: 'ok',   lat: '—' },
    { id: 'embed',     label: 'Embeddings',       status: 'warn', lat: '842ms' },
    { id: 'chat',      label: 'Chat provider',    status: 'ok',   lat: '410ms' },
    { id: 'sched',     label: 'Scheduler',        status: 'ok',   lat: '4m ago' },
    { id: 'browsersh', label: 'Browsershot',      status: 'ok',   lat: '—' },
  ];
  return (
    <div className="panel" style={{ padding: 14, display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: 10 }}>
      {checks.map(c => (
        <div key={c.id} style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '6px 10px', background: 'var(--bg-2)', borderRadius: 8, border: '1px solid var(--panel-border)' }}>
          <span className={`pulse-dot ${c.status === 'warn' ? 'warn' : c.status === 'err' ? 'err' : ''}`} style={{ width: 7, height: 7 }}/>
          <div style={{ flex: 1, minWidth: 0 }}>
            <div style={{ fontSize: 12, color: 'var(--fg-0)', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{c.label}</div>
            <div className="mono" style={{ fontSize: 10.5, color: 'var(--fg-3)' }}>{c.lat}</div>
          </div>
        </div>
      ))}
    </div>
  );
}

function DashboardView() {
  const [liveTick, setTick] = uSd(0);
  uEd(() => { const t = setInterval(() => setTick(x => x + 1), 3000); return () => clearInterval(t); }, []);

  const kpis = uMd(() => [
    { label: 'Documents',   value: '1,591', sub: '4 projects · 12.3k chunks', delta: '+4.2%',  positive: true,  icon: 'File',     series: mkSeries(18, 1500, 40) },
    { label: 'Chats (24h)', value: '1,248', sub: 'p95 latency 842ms',          delta: '+18%',   positive: true,  icon: 'Chat',     series: mkSeries(24, 50, 18) },
    { label: 'Token burn',  value: '4.8M',  sub: '$36.12 · 7d rolling',        delta: '-3.1%',  positive: true,  icon: 'Zap',      series: mkSeries(18, 180, 60) },
    { label: 'Cache hit',   value: '74%',   sub: '+9% vs last week',           delta: '+9.0%',  positive: true,  icon: 'Database', series: mkSeries(18, 70, 8) },
    { label: 'Canonical',   value: '82%',   sub: '1,305 / 1,591 promoted',     delta: '+12',    positive: true,  icon: 'Sparkles', series: mkSeries(18, 78, 4) },
    { label: 'Failed jobs', value: '3',     sub: 'pending review',             delta: '+1',     positive: false, icon: 'Alert',    series: mkSeries(18, 3, 2) },
  ], [liveTick]);

  const volume = uMd(() => mkSeries(14, 150, 60), []);
  const tokens = uMd(() => Array.from({ length: 10 }, () => ({
    a: 20 + Math.random()*50, b: 30 + Math.random()*70, c: 10 + Math.random()*30
  })), []);

  return (
    <div style={{ flex: 1, overflow: 'auto', padding: 24 }} className="grid-bg">
      <div style={{ maxWidth: 1320, margin: '0 auto', display: 'flex', flexDirection: 'column', gap: 16 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
          <div>
            <h1 style={{ margin: 0, fontSize: 22, fontWeight: 600, letterSpacing: '-0.02em' }}>Admin dashboard</h1>
            <div style={{ fontSize: 12, color: 'var(--fg-2)', marginTop: 4, display: 'flex', gap: 8, alignItems: 'center' }}>
              <span className="pulse-dot" style={{ width: 6, height: 6 }}/>
              <span className="mono">live · refreshed {liveTick}s ago · global scope</span>
            </div>
          </div>
          <span style={{ flex: 1 }}/>
          <SegmentedControl options={[{ v: '24h', l: '24h' }, { v: '7d', l: '7d' }, { v: '30d', l: '30d' }, { v: '90d', l: '90d' }]} value="7d" onChange={() => {}}/>
          <button className="btn sm"><Icon.Download size={12}/>Export</button>
        </div>

        <HealthStrip/>

        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 12 }}>
          {kpis.map(k => <KpiCard key={k.label} {...k}/>)}
        </div>

        <div style={{ display: 'grid', gridTemplateColumns: '1.4fr 1fr', gap: 16 }}>
          <div className="panel popin" style={{ padding: 18 }}>
            <div style={{ display: 'flex', alignItems: 'center', marginBottom: 12 }}>
              <div>
                <div style={{ fontSize: 14, fontWeight: 600 }}>Chat volume</div>
                <div className="mono" style={{ fontSize: 11, color: 'var(--fg-3)', marginTop: 2 }}>last 14 days · all projects</div>
              </div>
              <span style={{ flex: 1 }}/>
              <div style={{ display: 'flex', gap: 10 }}>
                {PROJECTS.map(p => (
                  <span key={p.key} style={{ display: 'inline-flex', alignItems: 'center', gap: 5, fontSize: 10.5, color: 'var(--fg-2)', fontFamily: 'var(--font-mono)' }}>
                    <ProjectDot p={p} size={6}/>{p.label}
                  </span>
                ))}
              </div>
            </div>
            <AreaChart data={volume} width={720} height={210} labels={['Apr 8','9','10','11','12','13','14','15','16','17','18','19','20','21']}/>
          </div>
          <div className="panel popin" style={{ padding: 18 }}>
            <div style={{ display: 'flex', alignItems: 'center', marginBottom: 12 }}>
              <div>
                <div style={{ fontSize: 14, fontWeight: 600 }}>Token distribution</div>
                <div className="mono" style={{ fontSize: 11, color: 'var(--fg-3)', marginTop: 2 }}>per model · 10d</div>
              </div>
            </div>
            <BarStack data={tokens} width={420} height={180} labels={['4/12','13','14','15','16','17','18','19','20','21']}/>
            <div style={{ display: 'flex', gap: 12, marginTop: 10, fontSize: 11, color: 'var(--fg-2)' }}>
              <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5 }}><span style={{ width: 9, height: 9, background: '#8b5cf6', borderRadius: 2 }}/>sonnet</span>
              <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5 }}><span style={{ width: 9, height: 9, background: '#22d3ee', borderRadius: 2 }}/>haiku</span>
              <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5 }}><span style={{ width: 9, height: 9, background: 'var(--fg-3)', borderRadius: 2 }}/>embedding</span>
            </div>
          </div>
        </div>

        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 16 }}>
          {/* rating donut */}
          <div className="panel popin" style={{ padding: 18 }}>
            <div style={{ fontSize: 14, fontWeight: 600, marginBottom: 4 }}>Rating distribution</div>
            <div className="mono" style={{ fontSize: 11, color: 'var(--fg-3)' }}>last 7 days</div>
            <div style={{ display: 'flex', alignItems: 'center', gap: 16, marginTop: 10 }}>
              <Donut segments={[
                { v: 68, color: '#10b981' },
                { v: 8,  color: '#ef4444' },
                { v: 24, color: 'var(--fg-4)' },
              ]} size={130} stroke={18}/>
              <div style={{ flex: 1, display: 'flex', flexDirection: 'column', gap: 7 }}>
                <RatingRow color="#10b981" label="Positive" pct={68} count={843}/>
                <RatingRow color="#ef4444" label="Negative" pct={8}  count={99}/>
                <RatingRow color="var(--fg-4)" label="No rating" pct={24} count={298}/>
              </div>
            </div>
          </div>
          {/* top projects */}
          <div className="panel popin" style={{ padding: 18 }}>
            <div style={{ fontSize: 14, fontWeight: 600, marginBottom: 12 }}>Top projects</div>
            {PROJECTS.map((p, i) => {
              const pct = [88, 71, 52, 96][i];
              const vol = [624, 412, 289, 843][i];
              return (
                <div key={p.key} style={{ marginBottom: 10 }}>
                  <div style={{ display: 'flex', alignItems: 'center', fontSize: 12, marginBottom: 4 }}>
                    <ProjectDot p={p} size={7}/>
                    <span style={{ marginLeft: 8, color: 'var(--fg-0)' }}>{p.label}</span>
                    <span style={{ flex: 1 }}/>
                    <span className="mono" style={{ color: 'var(--fg-2)' }}>{vol} chats</span>
                  </div>
                  <div style={{ height: 6, background: 'var(--bg-3)', borderRadius: 4, overflow: 'hidden' }}>
                    <div style={{ width: `${pct}%`, height: '100%', background: `linear-gradient(90deg, ${p.color}, var(--accent-b))`, transition: 'width .6s' }}/>
                  </div>
                </div>
              );
            })}
          </div>
          {/* slowest queries */}
          <div className="panel popin" style={{ padding: 18 }}>
            <div style={{ fontSize: 14, fontWeight: 600, marginBottom: 12 }}>Slowest queries (p95)</div>
            {[
              { t: 'GDPR retention audit logs',    lat: 3240, proj: 'legal-vault' },
              { t: 'Vector store rationale ADR',   lat: 2118, proj: 'engineering' },
              { t: 'Month-close sign-off flow',    lat: 1842, proj: 'finance-ops' },
              { t: 'Parental leave EU breakdown',  lat: 1412, proj: 'hr-portal' },
            ].map((q, i) => {
              const p = PROJECTS.find(x => x.key === q.proj);
              return (
                <div key={i} style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '6px 0', borderBottom: i < 3 ? '1px solid var(--hairline)' : 0 }}>
                  <ProjectDot p={p} size={7}/>
                  <span style={{ flex: 1, fontSize: 12, color: 'var(--fg-1)', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{q.t}</span>
                  <span className="mono" style={{ fontSize: 11, color: q.lat > 2000 ? '#fca5a5' : 'var(--fg-2)' }}>{q.lat}ms</span>
                </div>
              );
            })}
          </div>
        </div>

        <div style={{ display: 'grid', gridTemplateColumns: '1fr 340px', gap: 16 }}>
          {/* coverage heatmap */}
          <div className="panel popin" style={{ padding: 18 }}>
            <div style={{ display: 'flex', alignItems: 'center', marginBottom: 12 }}>
              <div>
                <div style={{ fontSize: 14, fontWeight: 600 }}>Canonical coverage map</div>
                <div className="mono" style={{ fontSize: 11, color: 'var(--fg-3)', marginTop: 2 }}>documents × retrieval frequency</div>
              </div>
              <span style={{ flex: 1 }}/>
              <span className="pill accent">82% canonical</span>
            </div>
            <CoverageHeatmap/>
          </div>
          {/* activity feed */}
          <div className="panel popin" style={{ padding: 18, display: 'flex', flexDirection: 'column' }}>
            <div style={{ display: 'flex', alignItems: 'center', marginBottom: 12 }}>
              <div style={{ fontSize: 14, fontWeight: 600 }}>Activity</div>
              <span style={{ flex: 1 }}/>
              <span className="pulse-dot" style={{ width: 6, height: 6 }}/>
            </div>
            <div style={{ display: 'flex', flexDirection: 'column', gap: 4, maxHeight: 360, overflow: 'auto' }}>
              {ACTIVITY.map(a => {
                const Ico = Icon[a.icon] || Icon.Activity;
                return (
                  <div key={a.id} style={{ display: 'flex', gap: 10, padding: '8px 6px', borderRadius: 6 }}>
                    <div style={{ width: 24, height: 24, borderRadius: 6, background: 'var(--bg-3)', border: '1px solid var(--panel-border)', display: 'flex', alignItems: 'center', justifyContent: 'center', flex: '0 0 auto', color: 'var(--fg-2)' }}>
                      <Ico size={12}/>
                    </div>
                    <div style={{ flex: 1, minWidth: 0 }}>
                      <div style={{ fontSize: 12, color: 'var(--fg-1)', lineHeight: 1.45 }}>
                        <span style={{ color: 'var(--fg-0)', fontWeight: 500 }} className="mono">{a.actor}</span>
                        <span style={{ color: 'var(--fg-3)' }}> {a.action} </span>
                        <span className="mono">{a.target}</span>
                      </div>
                      <div className="mono" style={{ fontSize: 10.5, color: 'var(--fg-3)', marginTop: 2 }}>{a.project} · {a.when}</div>
                    </div>
                  </div>
                );
              })}
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}

function RatingRow({ color, label, pct, count }) {
  return (
    <div>
      <div style={{ display: 'flex', fontSize: 11.5, marginBottom: 3 }}>
        <span style={{ width: 8, height: 8, background: color, borderRadius: 2, marginRight: 7, marginTop: 4 }}/>
        <span style={{ color: 'var(--fg-1)' }}>{label}</span>
        <span style={{ flex: 1 }}/>
        <span className="mono" style={{ color: 'var(--fg-2)' }}>{count} · {pct}%</span>
      </div>
      <div style={{ height: 4, background: 'var(--bg-3)', borderRadius: 2 }}>
        <div style={{ width: `${pct}%`, height: '100%', background: color, borderRadius: 2, transition: 'width .6s' }}/>
      </div>
    </div>
  );
}

function CoverageHeatmap() {
  const cols = 28, rows = 6;
  const cells = uMd(() =>
    Array.from({ length: rows * cols }, () => Math.random()),
  []);
  return (
    <div>
      <div style={{ display: 'grid', gridTemplateColumns: `repeat(${cols}, 1fr)`, gap: 3, marginBottom: 10 }}>
        {cells.map((v, i) => {
          const alpha = v < 0.15 ? 0.08 : 0.15 + v * 0.85;
          const c = v > 0.85 ? '#22d3ee' : v > 0.6 ? '#8b5cf6' : v > 0.3 ? '#6d28d9' : '#3b2870';
          return <div key={i} title={`q=${Math.round(v*100)}`} style={{ aspectRatio: '1', borderRadius: 3, background: c, opacity: alpha, transition: 'opacity .4s', animation: `popin .4s ${i*4}ms both` }}/>;
        })}
      </div>
      <div style={{ display: 'flex', fontSize: 10.5, color: 'var(--fg-3)', fontFamily: 'var(--font-mono)' }}>
        <span>low retrieval</span>
        <span style={{ flex: 1 }}/>
        <span>high retrieval</span>
      </div>
    </div>
  );
}

Object.assign(window, { DashboardView });

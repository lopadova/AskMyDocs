// Global shell — topbar, sidebar, command palette, tweaks panel
const { useState, useEffect, useRef, useMemo, useCallback } = React;

function useTheme(initial = 'dark') {
  const [theme, setTheme] = useState(() => localStorage.getItem('amd-theme') || initial);
  useEffect(() => {
    document.documentElement.setAttribute('data-theme', theme);
    localStorage.setItem('amd-theme', theme);
  }, [theme]);
  return [theme, setTheme];
}

function useDensity(initial = 'balanced') {
  const [d, setD] = useState(() => localStorage.getItem('amd-density') || initial);
  useEffect(() => {
    document.documentElement.setAttribute('data-density', d);
    localStorage.setItem('amd-density', d);
  }, [d]);
  return [d, setD];
}

function useFontPair(initial = 'geist') {
  const [f, setF] = useState(() => localStorage.getItem('amd-font') || initial);
  useEffect(() => {
    const map = {
      geist:   ["'Geist'", "'Geist Mono'"],
      inter:   ["'Inter'", "'JetBrains Mono'"],
      plex:    ["'IBM Plex Sans'", "'IBM Plex Mono'"],
      satoshi: ["'Satoshi'", "'JetBrains Mono'"],
    };
    const [s, m] = map[f] || map.geist;
    document.documentElement.style.setProperty('--font-sans', `${s}, ui-sans-serif, system-ui, sans-serif`);
    document.documentElement.style.setProperty('--font-mono', `${m}, ui-monospace, Menlo, monospace`);
    localStorage.setItem('amd-font', f);
  }, [f]);
  return [f, setF];
}

function Avatar({ user, size = 28 }) {
  return (
    <div style={{
      width: size, height: size, borderRadius: 999,
      background: `linear-gradient(135deg, ${user.color || '#8b5cf6'}, ${user.color2 || '#22d3ee'})`,
      display: 'flex', alignItems: 'center', justifyContent: 'center',
      fontSize: Math.max(10, size * 0.38), fontWeight: 600, color: '#0a0a14',
      flex: '0 0 auto',
    }}>
      {user.avatar || user.name?.split(' ').map(n => n[0]).slice(0,2).join('')}
    </div>
  );
}

function ProjectDot({ p, size = 8 }) {
  return <span style={{ width: size, height: size, borderRadius: 99, background: p.color, display: 'inline-block', flex: '0 0 auto' }}/>;
}

function Tooltip({ children, label, side = 'bottom' }) {
  const [open, setOpen] = useState(false);
  return (
    <span style={{ position: 'relative', display: 'inline-flex' }} onMouseEnter={() => setOpen(true)} onMouseLeave={() => setOpen(false)}>
      {children}
      {open && (
        <span style={{
          position: 'absolute', [side]: 'calc(100% + 6px)', left: '50%', transform: 'translateX(-50%)',
          background: 'var(--bg-4)', color: 'var(--fg-0)', fontSize: 11, padding: '5px 8px',
          borderRadius: 6, whiteSpace: 'nowrap', zIndex: 50, pointerEvents: 'none',
          border: '1px solid var(--panel-border)', boxShadow: 'var(--shadow)',
        }}>{label}</span>
      )}
    </span>
  );
}

function Sidebar({ active, onNav, collapsed }) {
  const items = [
    { id: 'chat',        label: 'Chat',        icon: 'Chat',     section: 'workspace' },
    { id: 'dashboard',   label: 'Dashboard',   icon: 'Grid',     section: 'admin' },
    { id: 'kb',          label: 'Knowledge',   icon: 'Book',     section: 'admin' },
    { id: 'insights',    label: 'AI Insights', icon: 'Sparkles', section: 'admin', badge: 5 },
    { id: 'users',       label: 'Users & Roles', icon: 'Users',  section: 'admin' },
    { id: 'logs',        label: 'Logs',        icon: 'Activity', section: 'ops' },
    { id: 'maintenance', label: 'Maintenance', icon: 'Wrench',   section: 'ops' },
  ];
  const sections = [
    { id: 'workspace', label: 'Workspace' },
    { id: 'admin',     label: 'Administration' },
    { id: 'ops',       label: 'Operations' },
  ];
  return (
    <aside style={{
      width: collapsed ? 60 : 232, minWidth: collapsed ? 60 : 232,
      borderRight: '1px solid var(--hairline)',
      background: 'var(--bg-1)',
      display: 'flex', flexDirection: 'column',
      transition: 'width .22s',
    }}>
      <div style={{ padding: '14px 14px 10px', display: 'flex', alignItems: 'center', gap: 10 }}>
        <Icon.Logo size={22}/>
        {!collapsed && (
          <div style={{ minWidth: 0 }}>
            <div style={{ fontSize: 13.5, fontWeight: 600, letterSpacing: '-0.01em' }}>AskMyDocs</div>
            <div style={{ fontSize: 10.5, color: 'var(--fg-3)', fontFamily: 'var(--font-mono)', textTransform: 'uppercase', letterSpacing: '0.04em' }}>Enterprise</div>
          </div>
        )}
      </div>
      <div style={{ padding: collapsed ? '4px 8px 8px' : '4px 10px 8px' }}>
        <button className="focus-ring" style={{
          width: '100%', display: 'flex', alignItems: 'center', gap: 10,
          padding: collapsed ? '8px 10px' : '8px 10px',
          background: 'var(--bg-2)', border: '1px solid var(--panel-border)',
          borderRadius: 9, color: 'var(--fg-2)', fontSize: 12, cursor: 'pointer',
          justifyContent: collapsed ? 'center' : 'flex-start',
        }} onClick={() => window.dispatchEvent(new CustomEvent('amd:palette'))}>
          <Icon.Search size={14}/>
          {!collapsed && <><span style={{ flex: 1, textAlign: 'left' }}>Search…</span><span className="kbd">⌘K</span></>}
        </button>
      </div>
      <nav style={{ flex: 1, overflow: 'auto', padding: '4px 10px 10px' }}>
        {sections.map(sec => (
          <div key={sec.id} style={{ marginTop: 10 }}>
            {!collapsed && <div style={{ fontSize: 10, color: 'var(--fg-3)', textTransform: 'uppercase', letterSpacing: '.08em', padding: '6px 10px 4px', fontFamily: 'var(--font-mono)' }}>{sec.label}</div>}
            {items.filter(i => i.section === sec.id).map(it => {
              const Ico = Icon[it.icon];
              const isActive = active === it.id;
              return (
                <button key={it.id} onClick={() => onNav(it.id)} className="focus-ring" style={{
                  width: '100%', display: 'flex', alignItems: 'center', gap: 10,
                  padding: collapsed ? '8px 10px' : '7px 10px',
                  background: isActive ? 'var(--bg-3)' : 'transparent',
                  color: isActive ? 'var(--fg-0)' : 'var(--fg-2)',
                  border: '1px solid ' + (isActive ? 'var(--panel-border)' : 'transparent'),
                  borderRadius: 8, cursor: 'pointer',
                  fontSize: 13, fontWeight: isActive ? 500 : 400,
                  justifyContent: collapsed ? 'center' : 'flex-start',
                  position: 'relative',
                  marginBottom: 2,
                }}>
                  {isActive && <span style={{ position: 'absolute', left: -10, top: 6, bottom: 6, width: 2, background: 'var(--grad-accent)', borderRadius: 2 }}/>}
                  <Ico size={15}/>
                  {!collapsed && (
                    <>
                      <span style={{ flex: 1, textAlign: 'left' }}>{it.label}</span>
                      {it.badge && <span style={{
                        fontSize: 10, padding: '2px 6px', borderRadius: 99,
                        background: 'var(--grad-accent-soft)', color: 'var(--fg-0)',
                        fontWeight: 500, border: '1px solid rgba(139,92,246,.3)',
                      }}>{it.badge}</span>}
                    </>
                  )}
                </button>
              );
            })}
          </div>
        ))}
      </nav>
      <div style={{ padding: 10, borderTop: '1px solid var(--hairline)', display: 'flex', alignItems: 'center', gap: 10 }}>
        <Avatar user={USERS[0]} size={28}/>
        {!collapsed && (
          <div style={{ minWidth: 0, flex: 1 }}>
            <div style={{ fontSize: 12.5, fontWeight: 500, color: 'var(--fg-0)', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{USERS[0].name}</div>
            <div style={{ fontSize: 10.5, color: 'var(--fg-3)', fontFamily: 'var(--font-mono)' }}>super-admin · 4 projects</div>
          </div>
        )}
      </div>
    </aside>
  );
}

function Topbar({ project, onProjectChange, theme, setTheme, onToggleTweaks, crumbs = [] }) {
  return (
    <header style={{
      height: 52, flex: '0 0 52px',
      borderBottom: '1px solid var(--hairline)',
      background: 'var(--bg-1)',
      display: 'flex', alignItems: 'center', gap: 12, padding: '0 16px',
      position: 'relative', zIndex: 5,
    }}>
      <ProjectSwitcher project={project} onChange={onProjectChange}/>
      <div style={{ display: 'flex', alignItems: 'center', gap: 6, color: 'var(--fg-3)', fontSize: 12 }}>
        {crumbs.map((c, i) => (
          <React.Fragment key={i}>
            <Icon.Chevron size={12}/>
            <span style={{ color: i === crumbs.length - 1 ? 'var(--fg-1)' : 'var(--fg-3)' }}>{c}</span>
          </React.Fragment>
        ))}
      </div>
      <div style={{ flex: 1 }}/>
      <div style={{ display: 'flex', alignItems: 'center', gap: 4, padding: '4px 10px', background: 'var(--bg-2)', border: '1px solid var(--panel-border)', borderRadius: 9, fontSize: 11.5, color: 'var(--fg-2)' }}>
        <span className="pulse-dot" style={{ width: 6, height: 6 }}/>
        <span className="mono">All systems operational</span>
      </div>
      <Tooltip label="Notifications">
        <button className="btn icon ghost" style={{ position: 'relative' }}>
          <Icon.Bell size={15}/>
          <span style={{ position: 'absolute', top: 6, right: 7, width: 6, height: 6, background: 'var(--accent-a)', borderRadius: 99, border: '1.5px solid var(--bg-1)' }}/>
        </button>
      </Tooltip>
      <Tooltip label={theme === 'dark' ? 'Light mode' : 'Dark mode'}>
        <button className="btn icon ghost" onClick={() => setTheme(theme === 'dark' ? 'light' : 'dark')}>
          {theme === 'dark' ? <Icon.Sun size={15}/> : <Icon.Moon size={15}/>}
        </button>
      </Tooltip>
      <Tooltip label="Tweaks">
        <button className="btn icon ghost" onClick={onToggleTweaks}>
          <Icon.Sliders size={15}/>
        </button>
      </Tooltip>
    </header>
  );
}

function ProjectSwitcher({ project, onChange }) {
  const [open, setOpen] = useState(false);
  const ref = useRef();
  useEffect(() => {
    const off = (e) => { if (!ref.current?.contains(e.target)) setOpen(false); };
    document.addEventListener('mousedown', off);
    return () => document.removeEventListener('mousedown', off);
  }, []);
  return (
    <div ref={ref} style={{ position: 'relative' }}>
      <button className="focus-ring" onClick={() => setOpen(!open)} style={{
        display: 'flex', alignItems: 'center', gap: 8, padding: '6px 10px',
        background: 'var(--bg-2)', border: '1px solid var(--panel-border)', borderRadius: 9,
        cursor: 'pointer', color: 'var(--fg-0)', fontSize: 12.5, fontWeight: 500,
      }}>
        <ProjectDot p={project} size={8}/>
        {project.label}
        <Icon.ChevronDown size={13} style={{ color: 'var(--fg-3)' }}/>
      </button>
      {open && (
        <div className="panel popin" style={{
          position: 'absolute', top: 'calc(100% + 6px)', left: 0, minWidth: 260,
          padding: 6, zIndex: 100, boxShadow: 'var(--shadow-lg)',
        }}>
          <div style={{ padding: '4px 8px 6px', fontSize: 10.5, color: 'var(--fg-3)', fontFamily: 'var(--font-mono)', textTransform: 'uppercase', letterSpacing: '.08em' }}>Switch project</div>
          {PROJECTS.map(p => (
            <button key={p.key} onClick={() => { onChange(p); setOpen(false); }} style={{
              width: '100%', display: 'flex', alignItems: 'center', gap: 10,
              padding: '8px 10px',
              background: project.key === p.key ? 'var(--bg-3)' : 'transparent',
              border: 0, borderRadius: 7, cursor: 'pointer',
              color: 'var(--fg-0)', fontSize: 13, textAlign: 'left',
            }}>
              <ProjectDot p={p} size={10}/>
              <span style={{ flex: 1 }}>{p.label}</span>
              <span className="mono" style={{ fontSize: 10.5, color: 'var(--fg-3)' }}>{p.docs} docs</span>
              {project.key === p.key && <Icon.Check size={13}/>}
            </button>
          ))}
        </div>
      )}
    </div>
  );
}

function CommandPalette() {
  const [open, setOpen] = useState(false);
  const [q, setQ] = useState('');
  const inputRef = useRef();
  useEffect(() => {
    const trigger = () => setOpen(o => !o);
    const key = (e) => {
      if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); trigger(); }
      if (e.key === 'Escape') setOpen(false);
    };
    window.addEventListener('amd:palette', trigger);
    window.addEventListener('keydown', key);
    return () => { window.removeEventListener('amd:palette', trigger); window.removeEventListener('keydown', key); };
  }, []);
  useEffect(() => { if (open) setTimeout(() => inputRef.current?.focus(), 20); else setQ(''); }, [open]);

  const items = [
    { icon: 'Chat',     label: 'New chat',                       group: 'Actions',  kbd: 'N' },
    { icon: 'Search',   label: 'Search knowledge base',          group: 'Actions',  kbd: '/' },
    { icon: 'Folder',   label: 'Open KB tree',                   group: 'Navigate', kbd: '' },
    { icon: 'Grid',     label: 'Admin dashboard',                group: 'Navigate', kbd: '' },
    { icon: 'Sparkles', label: 'AI Insights (5 new)',            group: 'Navigate', kbd: '' },
    { icon: 'Users',    label: 'Manage users',                   group: 'Admin',    kbd: '' },
    { icon: 'Wrench',   label: 'Run kb:validate-canonical',      group: 'Commands', kbd: '' },
    { icon: 'Wrench',   label: 'Run kb:rebuild-graph',           group: 'Commands', kbd: '' },
    { icon: 'File',     label: 'remote-work-policy.md',          group: 'Documents', kbd: '' },
    { icon: 'File',     label: 'incident-response.md',           group: 'Documents', kbd: '' },
    { icon: 'File',     label: 'data-protection.md',             group: 'Documents', kbd: '' },
  ];
  const filtered = q ? items.filter(i => i.label.toLowerCase().includes(q.toLowerCase())) : items;
  const grouped = filtered.reduce((acc, it) => {
    (acc[it.group] = acc[it.group] || []).push(it); return acc;
  }, {});

  if (!open) return null;
  return (
    <div onClick={() => setOpen(false)} style={{
      position: 'fixed', inset: 0, zIndex: 1000,
      background: 'rgba(0,0,0,0.55)', backdropFilter: 'blur(8px)',
      display: 'flex', alignItems: 'flex-start', justifyContent: 'center',
      paddingTop: '12vh',
    }}>
      <div onClick={(e) => e.stopPropagation()} className="panel popin" style={{
        width: 620, maxWidth: '92vw',
        background: 'var(--panel-solid)',
        boxShadow: 'var(--shadow-lg)',
        border: '1px solid var(--panel-border-strong)',
        overflow: 'hidden',
      }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '14px 16px', borderBottom: '1px solid var(--hairline)' }}>
          <Icon.Search size={16} style={{ color: 'var(--fg-3)' }}/>
          <input ref={inputRef} value={q} onChange={(e) => setQ(e.target.value)}
                 placeholder="Search commands, documents, users…"
                 style={{ flex: 1, background: 'transparent', border: 0, outline: 'none', color: 'var(--fg-0)', fontSize: 14, fontFamily: 'var(--font-sans)' }}/>
          <span className="kbd">ESC</span>
        </div>
        <div style={{ maxHeight: 420, overflow: 'auto', padding: 6 }}>
          {Object.entries(grouped).map(([g, its]) => (
            <div key={g} style={{ marginTop: 6 }}>
              <div style={{ padding: '6px 10px', fontSize: 10, color: 'var(--fg-3)', fontFamily: 'var(--font-mono)', textTransform: 'uppercase', letterSpacing: '.08em' }}>{g}</div>
              {its.map((it, i) => {
                const Ico = Icon[it.icon];
                return (
                  <div key={i} style={{
                    display: 'flex', alignItems: 'center', gap: 12, padding: '8px 10px',
                    borderRadius: 7, cursor: 'pointer', color: 'var(--fg-1)',
                  }} onMouseOver={(e) => e.currentTarget.style.background = 'var(--bg-3)'}
                     onMouseOut={(e) => e.currentTarget.style.background = 'transparent'}
                     onClick={() => setOpen(false)}>
                    <Ico size={14}/>
                    <span style={{ flex: 1, fontSize: 13 }}>{it.label}</span>
                    {it.kbd && <span className="kbd">{it.kbd}</span>}
                  </div>
                );
              })}
            </div>
          ))}
        </div>
        <div style={{ display: 'flex', alignItems: 'center', gap: 14, padding: '8px 14px', borderTop: '1px solid var(--hairline)', fontSize: 11, color: 'var(--fg-3)' }}>
          <span><span className="kbd">↑↓</span> navigate</span>
          <span><span className="kbd">⏎</span> select</span>
          <span style={{ flex: 1 }}/>
          <span className="mono">AskMyDocs v2.4.0</span>
        </div>
      </div>
    </div>
  );
}

function TweaksPanel({ open, onClose, theme, setTheme, density, setDensity, font, setFont, section, setSection }) {
  if (!open) return null;
  return (
    <div style={{
      position: 'fixed', right: 16, top: 72, zIndex: 500, width: 300,
    }} className="panel popin">
      <div style={{ padding: '12px 14px', display: 'flex', alignItems: 'center', borderBottom: '1px solid var(--hairline)' }}>
        <Icon.Sliders size={14}/>
        <span style={{ marginLeft: 8, fontSize: 13, fontWeight: 500 }}>Tweaks</span>
        <span style={{ flex: 1 }}/>
        <button className="btn icon sm ghost" onClick={onClose}><Icon.Close size={13}/></button>
      </div>
      <div style={{ padding: 14, display: 'flex', flexDirection: 'column', gap: 14 }}>
        <TweakRow label="Theme">
          <SegmentedControl options={[{ v: 'dark', l: 'Dark' }, { v: 'light', l: 'Light' }]} value={theme} onChange={setTheme}/>
        </TweakRow>
        <TweakRow label="Density">
          <SegmentedControl options={[{ v: 'compact', l: 'Compact' }, { v: 'balanced', l: 'Balanced' }, { v: 'comfortable', l: 'Comfort' }]} value={density} onChange={setDensity}/>
        </TweakRow>
        <TweakRow label="Typography">
          <SegmentedControl small options={[{ v: 'geist', l: 'Geist' }, { v: 'inter', l: 'Inter' }, { v: 'plex', l: 'Plex' }]} value={font} onChange={setFont}/>
        </TweakRow>
        <TweakRow label="Active section">
          <select className="input" value={section} onChange={(e) => setSection(e.target.value)} style={{ height: 30, fontSize: 12 }}>
            <option value="chat">Chat</option>
            <option value="dashboard">Admin Dashboard</option>
            <option value="kb">Knowledge Base</option>
            <option value="insights">AI Insights</option>
            <option value="users">Users &amp; Roles</option>
            <option value="logs">Logs</option>
            <option value="maintenance">Maintenance</option>
          </select>
        </TweakRow>
      </div>
    </div>
  );
}

function TweakRow({ label, children }) {
  return (
    <div>
      <div style={{ fontSize: 10.5, color: 'var(--fg-3)', textTransform: 'uppercase', letterSpacing: '.08em', fontFamily: 'var(--font-mono)', marginBottom: 6 }}>{label}</div>
      {children}
    </div>
  );
}

function SegmentedControl({ options, value, onChange, small }) {
  return (
    <div style={{ display: 'flex', background: 'var(--bg-2)', borderRadius: 8, padding: 3, border: '1px solid var(--panel-border)' }}>
      {options.map(o => (
        <button key={o.v} onClick={() => onChange(o.v)} style={{
          flex: 1, padding: small ? '5px 6px' : '6px 10px', border: 0, borderRadius: 6,
          background: value === o.v ? 'var(--bg-4)' : 'transparent',
          color: value === o.v ? 'var(--fg-0)' : 'var(--fg-2)',
          fontSize: small ? 11 : 12, fontWeight: 500, cursor: 'pointer',
          boxShadow: value === o.v ? 'var(--shadow-sm)' : 'none',
          transition: 'all .15s',
        }}>
          {o.l}
        </button>
      ))}
    </div>
  );
}

Object.assign(window, {
  useTheme, useDensity, useFontPair,
  Sidebar, Topbar, CommandPalette, TweaksPanel, Avatar, ProjectDot, Tooltip, SegmentedControl,
});

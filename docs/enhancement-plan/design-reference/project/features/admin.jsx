// Users & Roles, KB explorer, Logs, Maintenance, Insights
const { useState: uSu, useMemo: uMu, useEffect: uEu, useRef: uRu } = React;

/* =======================  USERS & ROLES  ======================= */
function UsersView() {
  const [selected, setSelected] = uSu(null);
  const [q, setQ] = uSu('');
  const [roleFilter, setRoleFilter] = uSu('all');
  const filtered = USERS.filter(u =>
    (roleFilter === 'all' || u.role === roleFilter) &&
    (!q || u.name.toLowerCase().includes(q.toLowerCase()) || u.email.toLowerCase().includes(q.toLowerCase())));

  return (
    <div style={{ flex: 1, display: 'flex', overflow: 'hidden' }}>
      <div style={{ flex: 1, overflow: 'auto', padding: 24 }}>
        <div style={{ maxWidth: 1180, margin: '0 auto' }}>
          <div style={{ display: 'flex', alignItems: 'center', marginBottom: 16 }}>
            <div>
              <h1 style={{ margin: 0, fontSize: 22, fontWeight: 600, letterSpacing: '-0.02em' }}>Users &amp; Roles</h1>
              <div className="mono" style={{ fontSize: 11.5, color: 'var(--fg-3)', marginTop: 4 }}>{USERS.length} members · 4 roles · Spatie + project scope</div>
            </div>
            <span style={{ flex: 1 }}/>
            <button className="btn"><Icon.Download size={12}/>Export</button>
            <button className="btn primary" style={{ marginLeft: 8 }}><Icon.Plus size={13}/>Invite user</button>
          </div>
          <div className="panel" style={{ padding: 10, display: 'flex', gap: 10, alignItems: 'center', marginBottom: 12 }}>
            <div style={{ position: 'relative', flex: 1 }}>
              <Icon.Search size={12} style={{ position: 'absolute', left: 10, top: 10, color: 'var(--fg-3)' }}/>
              <input className="input" value={q} onChange={e => setQ(e.target.value)} placeholder="Search by name or email…" style={{ paddingLeft: 30, height: 32 }}/>
            </div>
            <SegmentedControl small options={[{v:'all',l:'All'},{v:'super-admin',l:'Admin'},{v:'editor',l:'Editor'},{v:'viewer',l:'Viewer'}]} value={roleFilter} onChange={setRoleFilter}/>
          </div>
          <div className="panel" style={{ overflow: 'hidden' }}>
            <div style={{ display: 'grid', gridTemplateColumns: '1.4fr 1fr 1.4fr 0.7fr 0.8fr 40px', padding: '10px 16px', borderBottom: '1px solid var(--hairline)', fontSize: 10.5, color: 'var(--fg-3)', textTransform: 'uppercase', letterSpacing: '.08em', fontFamily: 'var(--font-mono)' }}>
              <span>Name</span><span>Role</span><span>Projects</span><span>Status</span><span>Last seen</span><span/>
            </div>
            {filtered.map(u => (
              <div key={u.id} onClick={() => setSelected(u)} style={{ display: 'grid', gridTemplateColumns: '1.4fr 1fr 1.4fr 0.7fr 0.8fr 40px', padding: '10px 16px', borderBottom: '1px solid var(--hairline)', cursor: 'pointer', alignItems: 'center', background: selected?.id === u.id ? 'var(--bg-3)' : 'transparent' }}
                   onMouseOver={e => { if (selected?.id !== u.id) e.currentTarget.style.background = 'var(--bg-2)'; }}
                   onMouseOut={e => { if (selected?.id !== u.id) e.currentTarget.style.background = 'transparent'; }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                  <Avatar user={u} size={28}/>
                  <div>
                    <div style={{ fontSize: 13, color: 'var(--fg-0)', fontWeight: 500 }}>{u.name}</div>
                    <div className="mono" style={{ fontSize: 10.5, color: 'var(--fg-3)' }}>{u.email}</div>
                  </div>
                </div>
                <div><span className={`pill ${u.role === 'super-admin' ? 'accent' : ''}`}>{u.role}</span></div>
                <div style={{ display: 'flex', gap: 4, flexWrap: 'wrap' }}>
                  {u.projects.slice(0, 3).map(pk => {
                    const p = PROJECTS.find(x => x.key === pk);
                    return <span key={pk} style={{ display: 'inline-flex', alignItems: 'center', gap: 5, padding: '2px 7px', background: 'var(--bg-2)', border: '1px solid var(--panel-border)', borderRadius: 99, fontSize: 10.5 }}><ProjectDot p={p} size={6}/>{p.label.split(' ')[0].toLowerCase()}</span>;
                  })}
                  {u.projects.length > 3 && <span style={{ fontSize: 10.5, color: 'var(--fg-3)' }} className="mono">+{u.projects.length - 3}</span>}
                </div>
                <div><span className={`pill ${u.active ? 'ok' : ''}`}>{u.active ? 'active' : 'inactive'}</span></div>
                <div className="mono" style={{ fontSize: 11, color: 'var(--fg-2)' }}>{u.last}</div>
                <button className="btn icon sm ghost" onClick={e => { e.stopPropagation(); setSelected(u); }}><Icon.Chevron size={13}/></button>
              </div>
            ))}
          </div>
        </div>
      </div>
      {selected && <UserDrawer user={selected} onClose={() => setSelected(null)}/>}
    </div>
  );
}

function UserDrawer({ user, onClose }) {
  const [tab, setTab] = uSu('profile');
  const permMatrix = [
    ['Knowledge base', ['kb.read.any', 'kb.edit.any', 'kb.delete.any', 'kb.promote']],
    ['Users & Roles',  ['users.manage', 'roles.manage']],
    ['Operations',     ['commands.run', 'logs.view', 'insights.view']],
  ];
  const granted = new Set(user.role === 'super-admin' ? permMatrix.flatMap(([,p]) => p) :
                          user.role === 'admin' ? ['kb.read.any','kb.edit.any','users.manage','logs.view','insights.view'] :
                          user.role === 'editor' ? ['kb.read.any','kb.edit.any'] : ['kb.read.any']);
  return (
    <div className="popin" style={{ width: 440, flex: '0 0 440px', borderLeft: '1px solid var(--hairline)', background: 'var(--bg-1)', display: 'flex', flexDirection: 'column' }}>
      <div style={{ padding: 18, borderBottom: '1px solid var(--hairline)' }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
          <Avatar user={user} size={44}/>
          <div style={{ flex: 1, minWidth: 0 }}>
            <div style={{ fontSize: 15, fontWeight: 600 }}>{user.name}</div>
            <div className="mono" style={{ fontSize: 11, color: 'var(--fg-2)' }}>{user.email}</div>
          </div>
          <button className="btn icon sm ghost" onClick={onClose}><Icon.Close size={13}/></button>
        </div>
        <div style={{ display: 'flex', gap: 6, marginTop: 12 }}>
          <span className={`pill ${user.role === 'super-admin' ? 'accent' : ''}`}>{user.role}</span>
          <span className={`pill ${user.active ? 'ok' : ''}`}>{user.active ? 'active' : 'inactive'}</span>
          <span className="pill">2FA off</span>
        </div>
      </div>
      <div style={{ display: 'flex', gap: 2, padding: '6px 10px', borderBottom: '1px solid var(--hairline)' }}>
        {['profile','roles','projects','activity'].map(t => (
          <button key={t} onClick={() => setTab(t)} style={{ padding: '7px 12px', background: tab === t ? 'var(--bg-3)' : 'transparent', border: 0, borderRadius: 6, color: tab === t ? 'var(--fg-0)' : 'var(--fg-2)', fontSize: 12, cursor: 'pointer', textTransform: 'capitalize', fontWeight: tab === t ? 500 : 400 }}>{t}</button>
        ))}
      </div>
      <div style={{ flex: 1, overflow: 'auto', padding: 18 }}>
        {tab === 'profile' && (
          <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
            <Field label="Display name"><input className="input" defaultValue={user.name}/></Field>
            <Field label="Email"><input className="input" defaultValue={user.email}/></Field>
            <Field label="Locale">
              <SegmentedControl options={[{v:'en',l:'English'},{v:'it',l:'Italiano'}]} value="en" onChange={()=>{}}/>
            </Field>
          </div>
        )}
        {tab === 'roles' && (
          <div>
            <div style={{ fontSize: 11, color: 'var(--fg-3)', textTransform: 'uppercase', letterSpacing: '.08em', fontFamily: 'var(--font-mono)', marginBottom: 10 }}>Permission matrix</div>
            {permMatrix.map(([group, perms]) => (
              <div key={group} style={{ marginBottom: 16 }}>
                <div style={{ fontSize: 12, color: 'var(--fg-0)', fontWeight: 500, marginBottom: 8 }}>{group}</div>
                {perms.map(p => (
                  <label key={p} style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '6px 8px', borderRadius: 6, cursor: 'pointer' }}>
                    <input type="checkbox" defaultChecked={granted.has(p)} style={{ accentColor: '#8b5cf6' }}/>
                    <span className="mono" style={{ fontSize: 12, color: granted.has(p) ? 'var(--fg-0)' : 'var(--fg-2)' }}>{p}</span>
                  </label>
                ))}
              </div>
            ))}
          </div>
        )}
        {tab === 'projects' && (
          <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
            {PROJECTS.map(p => {
              const has = user.projects.includes(p.key);
              return (
                <div key={p.key} style={{ padding: 12, background: has ? 'var(--bg-2)' : 'transparent', border: '1px solid var(--panel-border)', borderRadius: 8 }}>
                  <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: has ? 10 : 0 }}>
                    <ProjectDot p={p} size={8}/>
                    <span style={{ fontSize: 13, fontWeight: 500, flex: 1 }}>{p.label}</span>
                    <label style={{ display: 'inline-flex', alignItems: 'center', gap: 6, fontSize: 11, color: 'var(--fg-2)', cursor: 'pointer' }}>
                      <input type="checkbox" defaultChecked={has} style={{ accentColor: '#8b5cf6' }}/>
                      member
                    </label>
                  </div>
                  {has && (
                    <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                      <span className="pill"><Icon.Folder size={10}/>policies/**</span>
                      <span className="pill"><Icon.Tag size={10}/>hr, policy</span>
                      <button className="btn sm ghost" style={{ height: 22, fontSize: 10.5 }}><Icon.Plus size={10}/>scope</button>
                    </div>
                  )}
                </div>
              );
            })}
          </div>
        )}
        {tab === 'activity' && (
          <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
            {['logged in','promoted remote-work-policy.md','updated data-protection.md','exported legal-vault/msa-template-v3.md as PDF'].map((a, i) => (
              <div key={i} style={{ display: 'flex', gap: 10, padding: 10, background: 'var(--bg-2)', border: '1px solid var(--panel-border)', borderRadius: 7 }}>
                <Icon.Activity size={14} style={{ color: 'var(--fg-2)', marginTop: 2 }}/>
                <div style={{ flex: 1 }}>
                  <div style={{ fontSize: 12 }}>{a}</div>
                  <div className="mono" style={{ fontSize: 10.5, color: 'var(--fg-3)', marginTop: 2 }}>{i*12 + 3}m ago · 185.14.22.44</div>
                </div>
              </div>
            ))}
          </div>
        )}
      </div>
      <div style={{ padding: 14, borderTop: '1px solid var(--hairline)', display: 'flex', gap: 8 }}>
        <button className="btn ghost" style={{ flex: 1 }}>Impersonate</button>
        <button className="btn primary" style={{ flex: 1 }}>Save changes</button>
      </div>
    </div>
  );
}

function Field({ label, children }) {
  return (
    <div>
      <div style={{ fontSize: 11, color: 'var(--fg-3)', textTransform: 'uppercase', letterSpacing: '.08em', fontFamily: 'var(--font-mono)', marginBottom: 6 }}>{label}</div>
      {children}
    </div>
  );
}

/* =======================  KB EXPLORER  ======================= */
function TreeNode({ node, depth, activePath, onOpen, path = '' }) {
  const [open, setOpen] = uSu(depth < 2);
  const fullPath = path ? `${path}/${node.name}` : node.name;
  const kindIcon = { decision: 'Bolt', policy: 'Shield', runbook: 'Terminal', reference: 'Book' };
  const kindColor = { decision: '#f97316', policy: '#8b5cf6', runbook: '#a3e635', reference: '#22d3ee' };
  if (node.type === 'folder') {
    return (
      <div>
        <button onClick={() => setOpen(!open)} style={{ display: 'flex', alignItems: 'center', gap: 6, width: '100%', padding: '4px 6px 4px ' + (depth*14 + 6) + 'px', background: 'transparent', border: 0, color: 'var(--fg-1)', fontSize: 12.5, cursor: 'pointer', borderRadius: 5 }}
                onMouseOver={e => e.currentTarget.style.background = 'var(--bg-2)'} onMouseOut={e => e.currentTarget.style.background = 'transparent'}>
          <Icon.Chevron size={11} style={{ transform: open ? 'rotate(90deg)' : 'none', transition: 'transform .15s', color: 'var(--fg-3)' }}/>
          <Icon.Folder size={13} style={{ color: 'var(--fg-2)' }}/>
          <span style={{ textAlign: 'left' }}>{node.name}</span>
          <span style={{ flex: 1 }}/>
          <span className="mono" style={{ fontSize: 10, color: 'var(--fg-3)' }}>{node.children?.length}</span>
        </button>
        {open && node.children?.map(c => <TreeNode key={c.name} node={c} depth={depth + 1} activePath={activePath} onOpen={onOpen} path={fullPath}/>)}
      </div>
    );
  }
  const active = activePath === fullPath;
  const Ico = Icon[kindIcon[node.kind]] || Icon.File;
  return (
    <button onClick={() => onOpen(fullPath, node)} style={{ display: 'flex', alignItems: 'center', gap: 6, width: '100%', padding: '4px 6px 4px ' + (depth*14 + 6) + 'px', background: active ? 'var(--bg-3)' : 'transparent', border: '1px solid ' + (active ? 'var(--panel-border)' : 'transparent'), color: 'var(--fg-1)', fontSize: 12.5, cursor: 'pointer', borderRadius: 5 }}
            onMouseOver={e => { if (!active) e.currentTarget.style.background = 'var(--bg-2)'; }}
            onMouseOut={e => { if (!active) e.currentTarget.style.background = 'transparent'; }}>
      <span style={{ width: 11 }}/>
      <Ico size={13} style={{ color: kindColor[node.kind] }}/>
      <span style={{ textAlign: 'left', flex: 1, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{node.name.replace(/\.md$/, '')}</span>
      {node.status === 'canonical' && <Icon.Sparkles size={10} style={{ color: 'var(--accent-a)' }}/>}
      {node.status === 'draft' && <span className="pill warn" style={{ fontSize: 9, padding: '1px 5px' }}>draft</span>}
      {node.status === 'review' && <span className="pill" style={{ fontSize: 9, padding: '1px 5px' }}>review</span>}
    </button>
  );
}

function KbView() {
  const [project, setProject] = uSu('hr-portal');
  const [activePath, setActivePath] = uSu('policies/remote-work-policy.md');
  const [tab, setTab] = uSu('preview');
  const [search, setSearch] = uSu('');

  return (
    <div style={{ flex: 1, display: 'flex', overflow: 'hidden' }}>
      {/* tree */}
      <div style={{ width: 300, flex: '0 0 300px', borderRight: '1px solid var(--hairline)', background: 'var(--bg-1)', display: 'flex', flexDirection: 'column' }}>
        <div style={{ padding: 12 }}>
          <select className="input" value={project} onChange={e => setProject(e.target.value)} style={{ height: 30, fontSize: 12 }}>
            {PROJECTS.map(p => <option key={p.key} value={p.key}>{p.label}</option>)}
          </select>
        </div>
        <div style={{ padding: '0 12px 10px' }}>
          <div style={{ position: 'relative' }}>
            <Icon.Search size={11} style={{ position: 'absolute', left: 10, top: 9, color: 'var(--fg-3)' }}/>
            <input className="input" value={search} onChange={e => setSearch(e.target.value)} placeholder="Fuzzy search…" style={{ paddingLeft: 28, height: 28, fontSize: 11.5 }}/>
          </div>
          <div style={{ display: 'flex', gap: 4, marginTop: 8 }}>
            <span className="pill accent" style={{ fontSize: 9.5 }}>canonical</span>
            <span className="pill" style={{ fontSize: 9.5 }}>draft</span>
            <span className="pill" style={{ fontSize: 9.5 }}>review</span>
          </div>
        </div>
        <div style={{ flex: 1, overflow: 'auto', padding: '4px 8px 10px' }}>
          {(KB_TREE[project] || []).map(n => <TreeNode key={n.name} node={n} depth={0} activePath={activePath} onOpen={setActivePath}/>)}
        </div>
        <div style={{ padding: 10, borderTop: '1px solid var(--hairline)', display: 'flex', gap: 6 }}>
          <button className="btn sm" style={{ flex: 1 }}><Icon.Upload size={11}/>Ingest</button>
          <button className="btn sm ghost"><Icon.Branch size={11}/></button>
        </div>
      </div>

      {/* viewer */}
      <div style={{ flex: 1, display: 'flex', flexDirection: 'column', minWidth: 0 }}>
        <div style={{ padding: '12px 20px', borderBottom: '1px solid var(--hairline)', display: 'flex', alignItems: 'center', gap: 10 }}>
          <div style={{ flex: 1, minWidth: 0 }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
              <Icon.Shield size={14} style={{ color: '#8b5cf6' }}/>
              <span style={{ fontSize: 14, fontWeight: 600 }}>{SAMPLE_DOC.title}</span>
              <span className="pill accent" style={{ fontSize: 10 }}><Icon.Sparkles size={9}/>canonical</span>
            </div>
            <div className="mono" style={{ fontSize: 11, color: 'var(--fg-3)', marginTop: 3 }}>{SAMPLE_DOC.project} / {SAMPLE_DOC.path} · v{SAMPLE_DOC.version} · {SAMPLE_DOC.versionHash}</div>
          </div>
          <button className="btn icon sm ghost"><Icon.Eye size={13}/></button>
          <button className="btn icon sm ghost"><Icon.Download size={13}/></button>
          <button className="btn sm"><Icon.Share size={12}/>Copy link</button>
          <button className="btn primary sm"><Icon.Edit size={12}/>Edit</button>
        </div>
        <div style={{ display: 'flex', gap: 2, padding: '4px 12px', borderBottom: '1px solid var(--hairline)' }}>
          {['preview','source','graph','meta','history'].map(t => (
            <button key={t} onClick={() => setTab(t)} style={{ padding: '7px 12px', background: 'transparent', border: 0, borderBottom: '2px solid ' + (tab === t ? 'var(--accent-a)' : 'transparent'), color: tab === t ? 'var(--fg-0)' : 'var(--fg-2)', fontSize: 12, cursor: 'pointer', textTransform: 'capitalize', fontWeight: tab === t ? 500 : 400 }}>{t}</button>
          ))}
        </div>

        <div style={{ flex: 1, overflow: 'auto' }}>
          {tab === 'preview' && <DocPreview/>}
          {tab === 'source' && <DocSource/>}
          {tab === 'graph' && <div style={{ padding: 30 }}><RelatedGraph data={RELATED_GRAPH}/></div>}
          {tab === 'meta' && <DocMeta/>}
          {tab === 'history' && <DocHistory/>}
        </div>
      </div>
    </div>
  );
}

function DocPreview() {
  return (
    <div style={{ padding: '28px 40px', maxWidth: 820 }}>
      {/* Frontmatter pill pack */}
      <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6, marginBottom: 18, padding: 14, background: 'var(--bg-2)', border: '1px solid var(--panel-border)', borderRadius: 10 }}>
        <span className="pill accent"><Icon.Shield size={10}/>policy</span>
        <span className="pill ok">status: canonical</span>
        <span className="pill"><Icon.Tag size={10}/>remote</span>
        <span className="pill"><Icon.Tag size={10}/>hr</span>
        <span className="pill"><Icon.Tag size={10}/>2026</span>
        <span className="pill"><Icon.Users size={10}/>owners: elena.ricci, marco</span>
        <span className="pill"><Icon.Eye size={10}/>reviewers: chiara.r</span>
      </div>
      {renderMarkdown(SAMPLE_DOC.bodyMd.replace(/^---[\s\S]*?---\s*/, ''))}
    </div>
  );
}

function DocSource() {
  const body = SAMPLE_DOC.bodyMd;
  const lines = body.split('\n');
  return (
    <div style={{ display: 'flex', height: '100%' }}>
      <div style={{ width: 48, flex: '0 0 48px', background: 'var(--bg-2)', padding: '12px 0', borderRight: '1px solid var(--hairline)', color: 'var(--fg-3)', fontFamily: 'var(--font-mono)', fontSize: 11.5, textAlign: 'right', lineHeight: 1.6 }}>
        {lines.map((_, i) => <div key={i} style={{ padding: '0 10px' }}>{i+1}</div>)}
      </div>
      <div style={{ flex: 1, overflow: 'auto', padding: '12px 16px', fontFamily: 'var(--font-mono)', fontSize: 12.5, color: 'var(--fg-0)', lineHeight: 1.6, background: 'var(--bg-1)' }}>
        {lines.map((l, i) => {
          let colored = l;
          const isFrontmatter = l === '---' || (i < 10 && l.match(/^\w+:/));
          const isHeading = l.startsWith('#');
          const isCode = l.startsWith('```') || l.startsWith('    ');
          const isWiki = l.includes('[[');
          return (
            <div key={i} style={{ whiteSpace: 'pre-wrap', color: isHeading ? '#22d3ee' : isFrontmatter ? '#a78bfa' : 'var(--fg-1)' }}>
              {l.split(/(\[\[[^\]]+\]\])/).map((chunk, k) => chunk.startsWith('[[') ? <span key={k} style={{ color: '#22d3ee', borderBottom: '1px dashed rgba(34,211,238,.5)' }}>{chunk}</span> : chunk)}
            </div>
          );
        })}
      </div>
      {/* diff side */}
      <div style={{ width: 320, flex: '0 0 320px', borderLeft: '1px solid var(--hairline)', background: 'var(--bg-1)', display: 'flex', flexDirection: 'column' }}>
        <div style={{ padding: 12, borderBottom: '1px solid var(--hairline)', display: 'flex', alignItems: 'center', gap: 8 }}>
          <Icon.Git size={13}/>
          <span style={{ fontSize: 12, fontWeight: 500 }}>Diff vs canonical</span>
          <span style={{ flex: 1 }}/>
          <span className="mono" style={{ fontSize: 10, color: 'var(--fg-3)' }}>+4 −2</span>
        </div>
        <div style={{ flex: 1, overflow: 'auto', padding: 12, fontFamily: 'var(--font-mono)', fontSize: 11.5, lineHeight: 1.55 }}>
          <div style={{ color: 'var(--fg-3)' }}>@@ policies/remote-work-policy.md</div>
          <div style={{ padding: '2px 6px', background: 'rgba(239,68,68,.08)', color: '#fca5a5', borderLeft: '2px solid #ef4444', marginTop: 6 }}>- Stipend: €60/month for full remote</div>
          <div style={{ padding: '2px 6px', background: 'rgba(16,185,129,.08)', color: '#6ee7b7', borderLeft: '2px solid #10b981' }}>+ Stipend: €80/month for full remote</div>
          <div style={{ padding: '2px 6px', color: 'var(--fg-2)' }}> as of 2026-04-18</div>
          <div style={{ padding: '2px 6px', color: 'var(--fg-2)' }}> VPN mandatory for internal</div>
          <div style={{ padding: '2px 6px', background: 'rgba(16,185,129,.08)', color: '#6ee7b7', borderLeft: '2px solid #10b981' }}>+ See [[data-protection]] v2026.2</div>
          <div style={{ padding: '2px 6px', background: 'rgba(239,68,68,.08)', color: '#fca5a5', borderLeft: '2px solid #ef4444' }}>- See [[data-protection]] v2024.8</div>
        </div>
        <div style={{ padding: 10, borderTop: '1px solid var(--hairline)', display: 'flex', gap: 6 }}>
          <button className="btn sm ghost" style={{ flex: 1 }}>Discard</button>
          <button className="btn primary sm" style={{ flex: 1 }}>Save &amp; re-ingest</button>
        </div>
      </div>
    </div>
  );
}

function DocMeta() {
  return (
    <div style={{ padding: 30, maxWidth: 720 }}>
      <div className="panel" style={{ padding: 16, marginBottom: 16 }}>
        <div style={{ fontSize: 13, fontWeight: 600, marginBottom: 10, display: 'flex', alignItems: 'center', gap: 8 }}>
          <Icon.Sparkles size={13} style={{ color: 'var(--accent-a)' }}/>
          AI suggestions for this document
        </div>
        <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
          {[
            ['Add tag', '`eu-employment`', 'Mentioned 4 times, absent from tags'],
            ['Related doc', 'Link [[parental-leave-policy]]', 'High co-occurrence in retrieval'],
            ['Canonical status', 'Keep canonical', 'No promotion concerns detected'],
          ].map(([k, v, sub], i) => (
            <div key={i} style={{ display: 'flex', gap: 10, padding: 10, background: 'var(--bg-2)', border: '1px solid var(--panel-border)', borderRadius: 7 }}>
              <div style={{ flex: 1 }}>
                <div style={{ fontSize: 11, color: 'var(--fg-3)', fontFamily: 'var(--font-mono)', textTransform: 'uppercase', letterSpacing: '.08em' }}>{k}</div>
                <div style={{ fontSize: 13, color: 'var(--fg-0)', marginTop: 2 }}>{renderInlineMd(v)}</div>
                <div style={{ fontSize: 11, color: 'var(--fg-2)', marginTop: 2 }}>{sub}</div>
              </div>
              <button className="btn sm">Apply</button>
            </div>
          ))}
        </div>
      </div>
      <div className="panel" style={{ padding: 16 }}>
        <div style={{ fontSize: 13, fontWeight: 600, marginBottom: 10 }}>Access control</div>
        <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
          {[{who: 'role:admin', perm: 'edit', color: '#8b5cf6'}, {who: 'role:editor', perm: 'edit', color: '#8b5cf6'}, {who: 'user:chiara.r', perm: 'view', color: '#22d3ee'}, {who: 'role:viewer', perm: 'view', color: '#22d3ee'}].map((a, i) => (
            <div key={i} style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '6px 10px', background: 'var(--bg-2)', borderRadius: 6, fontSize: 12 }}>
              <Icon.Shield size={12} style={{ color: a.color }}/>
              <span className="mono">{a.who}</span>
              <span style={{ flex: 1 }}/>
              <span className="pill">{a.perm}</span>
              <button className="btn icon sm ghost"><Icon.Close size={11}/></button>
            </div>
          ))}
          <button className="btn sm ghost" style={{ alignSelf: 'flex-start', marginTop: 4 }}><Icon.Plus size={11}/>Add rule</button>
        </div>
      </div>
    </div>
  );
}

function DocHistory() {
  const events = [
    { actor: 'elena.ricci', action: 'promoted to canonical', when: '2m ago', hash: 'a7f2c19e', delta: null },
    { actor: 'marco',       action: 'updated stipend amounts', when: '14m ago', hash: '3b8c91d2', delta: '+2 −1' },
    { actor: 'scheduler',   action: 'reindexed (chunks: 14)', when: '1h ago', hash: '3b8c91d2', delta: null },
    { actor: 's.colombo',   action: 'added data-protection wikilink', when: '2d ago', hash: '88a7e21f', delta: '+1' },
    { actor: 'elena.ricci', action: 'created', when: '6d ago', hash: 'initial', delta: null },
  ];
  return (
    <div style={{ padding: 30, maxWidth: 720 }}>
      <div style={{ fontSize: 13, fontWeight: 600, marginBottom: 14 }}>Document history</div>
      <div style={{ position: 'relative', paddingLeft: 20 }}>
        <div style={{ position: 'absolute', left: 6, top: 10, bottom: 10, width: 1, background: 'var(--hairline)' }}/>
        {events.map((e, i) => (
          <div key={i} style={{ position: 'relative', padding: '12px 0', display: 'flex', gap: 14 }}>
            <div style={{ position: 'absolute', left: -20, top: 16, width: 13, height: 13, borderRadius: 99, background: 'var(--bg-1)', border: '2px solid var(--accent-a)', boxShadow: '0 0 0 3px var(--bg-1)' }}/>
            <div style={{ flex: 1 }}>
              <div style={{ fontSize: 12.5 }}>
                <span className="mono" style={{ color: 'var(--fg-0)', fontWeight: 500 }}>{e.actor}</span>
                <span style={{ color: 'var(--fg-2)' }}> {e.action}</span>
              </div>
              <div className="mono" style={{ fontSize: 10.5, color: 'var(--fg-3)', marginTop: 3 }}>{e.when} · hash {e.hash} {e.delta && `· ${e.delta}`}</div>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}

/* =======================  LOGS  ======================= */
function LogsView() {
  const [tab, setTab] = uSu('chat');
  const [live, setLive] = uSu(true);
  const [rows, setRows] = uSu(LOGS);

  uEu(() => {
    if (!live) return;
    const t = setInterval(() => {
      const proj = PROJECTS[Math.floor(Math.random()*4)];
      const lvl = Math.random() > 0.85 ? 'WARN' : 'INFO';
      const newRow = {
        t: new Date().toLocaleTimeString('en-GB'), level: lvl, project: proj.key,
        model: Math.random() > 0.5 ? 'claude-sonnet-4.5' : 'claude-haiku-4.5',
        lat: Math.floor(200 + Math.random()*1400), tok: Math.floor(300 + Math.random()*1400),
        msg: ['chat: policy lookup','chat: stipend question','retrieval: graph traversal','chat: adr lookup'][Math.floor(Math.random()*4)],
      };
      setRows(r => [newRow, ...r].slice(0, 40));
    }, 1800);
    return () => clearInterval(t);
  }, [live]);

  return (
    <div style={{ flex: 1, overflow: 'auto', padding: 24 }}>
      <div style={{ maxWidth: 1280, margin: '0 auto' }}>
        <div style={{ display: 'flex', alignItems: 'center', marginBottom: 16 }}>
          <div>
            <h1 style={{ margin: 0, fontSize: 22, fontWeight: 600, letterSpacing: '-0.02em' }}>Logs</h1>
            <div className="mono" style={{ fontSize: 11.5, color: 'var(--fg-3)', marginTop: 4 }}>chat · canonical audit · application · activity · failed jobs</div>
          </div>
          <span style={{ flex: 1 }}/>
          <button onClick={() => setLive(!live)} className="btn sm" style={{ background: live ? 'var(--grad-accent)' : 'var(--bg-3)', color: live ? '#0a0a14' : 'var(--fg-0)', border: 0 }}>
            {live ? <><span className="pulse-dot" style={{ width: 6, height: 6, background: '#0a0a14', boxShadow: '0 0 0 0 rgba(10,10,20,.4)' }}/>Live tail</> : <><Icon.Play size={11}/>Resume</>}
          </button>
          <button className="btn sm ghost" style={{ marginLeft: 6 }}><Icon.Download size={12}/>Export CSV</button>
        </div>
        <div className="panel" style={{ padding: 6, marginBottom: 12, display: 'flex', gap: 2 }}>
          {[
            { id: 'chat',     l: 'Chat logs',        n: 12483 },
            { id: 'canon',    l: 'Canonical audit',  n: 841 },
            { id: 'app',      l: 'Application',      n: 220 },
            { id: 'activity', l: 'Activity',         n: 1892 },
            { id: 'failed',   l: 'Failed jobs',      n: 3, warn: true },
          ].map(t => (
            <button key={t.id} onClick={() => setTab(t.id)} style={{ display: 'flex', alignItems: 'center', gap: 7, padding: '7px 12px', background: tab === t.id ? 'var(--bg-3)' : 'transparent', border: 0, borderRadius: 7, color: tab === t.id ? 'var(--fg-0)' : 'var(--fg-2)', fontSize: 12, fontWeight: tab === t.id ? 500 : 400, cursor: 'pointer' }}>
              {t.l}
              <span className="mono" style={{ fontSize: 10.5, color: t.warn ? '#fca5a5' : 'var(--fg-3)' }}>{t.n}</span>
            </button>
          ))}
        </div>
        <div className="panel" style={{ overflow: 'hidden' }}>
          <div style={{ display: 'grid', gridTemplateColumns: '80px 65px 110px 150px 70px 70px 1fr', padding: '10px 16px', borderBottom: '1px solid var(--hairline)', fontSize: 10.5, color: 'var(--fg-3)', textTransform: 'uppercase', letterSpacing: '.08em', fontFamily: 'var(--font-mono)' }}>
            <span>Time</span><span>Level</span><span>Project</span><span>Model</span><span>Lat</span><span>Tok</span><span>Message</span>
          </div>
          <div style={{ maxHeight: 520, overflow: 'auto' }}>
            {rows.map((r, i) => (
              <div key={i} className={i === 0 && live ? 'popin' : ''} style={{ display: 'grid', gridTemplateColumns: '80px 65px 110px 150px 70px 70px 1fr', padding: '7px 16px', borderBottom: '1px solid var(--hairline)', fontSize: 11.5, fontFamily: 'var(--font-mono)', alignItems: 'center' }}>
                <span style={{ color: 'var(--fg-3)' }}>{r.t}</span>
                <span style={{ color: r.level === 'ERROR' ? '#fca5a5' : r.level === 'WARN' ? '#fbbf24' : 'var(--fg-2)' }}>{r.level}</span>
                <span style={{ color: 'var(--fg-1)' }}>{r.project}</span>
                <span style={{ color: 'var(--fg-2)' }}>{r.model}</span>
                <span style={{ color: r.lat > 2000 ? '#fca5a5' : r.lat > 1000 ? '#fbbf24' : 'var(--fg-1)' }}>{r.lat || '—'}ms</span>
                <span style={{ color: 'var(--fg-2)' }}>{r.tok || '—'}</span>
                <span style={{ color: 'var(--fg-1)', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{r.msg}</span>
              </div>
            ))}
          </div>
        </div>
      </div>
    </div>
  );
}

/* =======================  MAINTENANCE  ======================= */
function MaintenanceView() {
  const groups = [
    { title: 'Knowledge base', cmds: [
      { name: 'kb:ingest-folder',       desc: 'Ingest all files under a folder path', destructive: false, est: '~2m per 1000 files' },
      { name: 'kb:validate-canonical',  desc: 'Validate frontmatter + canonical schema', destructive: false, est: '<30s' },
      { name: 'kb:promote',             desc: 'Promote a document to canonical', destructive: true, est: '~5s' },
      { name: 'kb:delete',              desc: 'Soft-delete a document', destructive: true, est: '<5s' },
      { name: 'kb:rebuild-graph',       desc: 'Rebuild kb_nodes/kb_edges from canonical', destructive: false, est: '~3m' },
    ]},
    { title: 'Pruning', cmds: [
      { name: 'kb:prune-deleted',         desc: 'Hard delete soft-deleted older than 30d', destructive: true, est: '~1m' },
      { name: 'kb:prune-embedding-cache', desc: 'Drop stale embedding cache rows', destructive: true, est: '<1m' },
      { name: 'kb:prune-orphan-files',    desc: 'Remove files with no DB row', destructive: true, est: '~30s' },
      { name: 'activity-log:prune',       desc: 'Prune activity log older than 90d', destructive: true, est: '<10s' },
    ]},
    { title: 'Queue', cmds: [
      { name: 'queue:retry all',    desc: 'Retry all failed jobs', destructive: false, est: 'depends on queue' },
      { name: 'queue:prune-failed', desc: 'Prune failed jobs older than 48h', destructive: true, est: '<5s' },
    ]},
  ];
  const [running, setRunning] = uSu(null);
  const [output, setOutput] = uSu([]);
  uEu(() => {
    if (!running) return;
    setOutput([]);
    const lines = [
      `[${new Date().toLocaleTimeString('en-GB')}] Starting ${running.name}…`,
      `[${new Date().toLocaleTimeString('en-GB')}] Scanning target…`,
      `[${new Date().toLocaleTimeString('en-GB')}] Processed 248 items (canonical: 204, raw: 44)`,
      `[${new Date().toLocaleTimeString('en-GB')}] Writing audit row (admin_command_audit)…`,
      `[${new Date().toLocaleTimeString('en-GB')}] ✓ Done. exit_code=0  duration=4.2s`,
    ];
    lines.forEach((l, i) => setTimeout(() => setOutput(o => [...o, l]), 400 + i*350));
    setTimeout(() => setRunning(null), 400 + lines.length * 350 + 400);
  }, [running]);

  return (
    <div style={{ flex: 1, overflow: 'auto', padding: 24 }}>
      <div style={{ maxWidth: 1180, margin: '0 auto' }}>
        <div style={{ display: 'flex', alignItems: 'center', marginBottom: 16 }}>
          <div>
            <h1 style={{ margin: 0, fontSize: 22, fontWeight: 600, letterSpacing: '-0.02em' }}>Maintenance</h1>
            <div className="mono" style={{ fontSize: 11.5, color: 'var(--fg-3)', marginTop: 4 }}>whitelisted artisan commands · audit trail · rate-limited</div>
          </div>
          <span style={{ flex: 1 }}/>
          <div className="pill accent"><Icon.Shield size={11}/>commands.run · super-admin</div>
        </div>

        <div className="panel popin" style={{ padding: 14, marginBottom: 16, display: 'flex', alignItems: 'center', gap: 14 }}>
          <Icon.Clock size={16} style={{ color: 'var(--accent-b)' }}/>
          <div>
            <div style={{ fontSize: 13, fontWeight: 500 }}>Scheduler active · next run 04:00</div>
            <div className="mono" style={{ fontSize: 10.5, color: 'var(--fg-3)', marginTop: 2 }}>activity-log:prune · admin-audit:prune · queue:prune-failed · notifications:prune · kb:prune-orphan-files</div>
          </div>
          <span style={{ flex: 1 }}/>
          <div style={{ display: 'flex', gap: 3 }}>
            {Array.from({ length: 30 }, (_, i) => (
              <div key={i} style={{ width: 4, height: 18 + (i%3)*3, background: i < 22 ? 'var(--accent-b)' : 'var(--bg-3)', borderRadius: 2, opacity: i < 22 ? 0.4 + (i/60) : 1 }}/>
            ))}
          </div>
          <span className="mono" style={{ fontSize: 10.5, color: 'var(--fg-3)' }}>30 day success</span>
        </div>

        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 12, marginBottom: 16 }}>
          {groups.map(g => (
            <div key={g.title} className="panel popin" style={{ padding: 16 }}>
              <div style={{ fontSize: 13, fontWeight: 600, marginBottom: 10, display: 'flex', alignItems: 'center', gap: 8 }}>
                <Icon.Wrench size={13} style={{ color: 'var(--fg-2)' }}/>
                {g.title}
              </div>
              <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
                {g.cmds.map(c => (
                  <div key={c.name} style={{ padding: 10, background: 'var(--bg-2)', border: '1px solid var(--panel-border)', borderRadius: 7 }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 6, marginBottom: 3 }}>
                      <span className="mono" style={{ fontSize: 11.5, color: 'var(--fg-0)', fontWeight: 500 }}>{c.name}</span>
                      {c.destructive && <span className="pill err" style={{ fontSize: 9 }}>destructive</span>}
                    </div>
                    <div style={{ fontSize: 11.5, color: 'var(--fg-2)', lineHeight: 1.45 }}>{c.desc}</div>
                    <div style={{ display: 'flex', alignItems: 'center', marginTop: 7 }}>
                      <span className="mono" style={{ fontSize: 10, color: 'var(--fg-3)' }}>{c.est}</span>
                      <span style={{ flex: 1 }}/>
                      <button className="btn sm" onClick={() => setRunning(c)} disabled={running}>
                        <Icon.Play size={10}/>Run
                      </button>
                    </div>
                  </div>
                ))}
              </div>
            </div>
          ))}
        </div>

        {running && (
          <div className="panel popin" style={{ padding: 16, marginBottom: 16 }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 10 }}>
              <div className="pulse-dot warn"/>
              <span style={{ fontSize: 13, fontWeight: 500 }}>Executing</span>
              <span className="mono" style={{ fontSize: 11.5, color: 'var(--fg-2)' }}>$ artisan {running.name}</span>
            </div>
            <div style={{ padding: 14, background: 'var(--bg-0)', border: '1px solid var(--panel-border)', borderRadius: 8, fontFamily: 'var(--font-mono)', fontSize: 11.5, minHeight: 120, color: 'var(--fg-1)', lineHeight: 1.6 }}>
              {output.map((l, i) => <div key={i}>{l}</div>)}
              {output.length < 5 && <div><span className="caret"/></div>}
            </div>
          </div>
        )}

        <div className="panel" style={{ padding: 16 }}>
          <div style={{ fontSize: 13, fontWeight: 600, marginBottom: 10 }}>Recent executions</div>
          <div style={{ display: 'grid', gridTemplateColumns: '100px 220px 1fr 120px 80px', fontSize: 10.5, color: 'var(--fg-3)', textTransform: 'uppercase', letterSpacing: '.08em', fontFamily: 'var(--font-mono)', padding: '6px 0', borderBottom: '1px solid var(--hairline)' }}>
            <span>When</span><span>Command</span><span>Actor</span><span>Exit</span><span>Duration</span>
          </div>
          {[
            ['2m ago',  'kb:rebuild-graph',     'elena.ricci', 0, '3.1s'],
            ['28m ago', 'kb:validate-canonical','scheduler',   0, '0.8s'],
            ['1h ago',  'kb:prune-orphan-files','scheduler',   0, '24s'],
            ['3h ago',  'queue:retry all',      'marco',       0, '1.2s'],
            ['1d ago',  'kb:promote',           'elena.ricci', 1, '12ms'],
          ].map((r, i) => (
            <div key={i} style={{ display: 'grid', gridTemplateColumns: '100px 220px 1fr 120px 80px', padding: '8px 0', borderBottom: i < 4 ? '1px solid var(--hairline)' : 0, fontSize: 12, fontFamily: 'var(--font-mono)', alignItems: 'center' }}>
              <span style={{ color: 'var(--fg-3)' }}>{r[0]}</span>
              <span style={{ color: 'var(--fg-0)' }}>{r[1]}</span>
              <span style={{ color: 'var(--fg-2)' }}>{r[2]}</span>
              <span style={{ color: r[3] === 0 ? '#6ee7b7' : '#fca5a5' }}>exit_code={r[3]}</span>
              <span style={{ color: 'var(--fg-2)' }}>{r[4]}</span>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}

/* =======================  AI INSIGHTS  ======================= */
function InsightsView() {
  const kindIcon = { promote: 'Sparkles', orphan: 'Alert', tags: 'Tag', stale: 'Clock', gap: 'Eye' };
  const kindColor = { promote: '#8b5cf6', orphan: '#f59e0b', tags: '#22d3ee', stale: '#ef4444', gap: '#a3e635' };
  const [open, setOpen] = uSu(INSIGHTS[0]?.id);
  return (
    <div style={{ flex: 1, overflow: 'auto', padding: 24 }} className="grid-bg">
      <div style={{ maxWidth: 1280, margin: '0 auto' }}>
        <div style={{ display: 'flex', alignItems: 'flex-end', marginBottom: 16 }}>
          <div>
            <h1 style={{ margin: 0, fontSize: 22, fontWeight: 600, letterSpacing: '-0.02em', display: 'flex', alignItems: 'center', gap: 8 }}>
              <Icon.Sparkles size={20} style={{ color: 'var(--accent-a)' }}/>
              AI Insights
            </h1>
            <div className="mono" style={{ fontSize: 11.5, color: 'var(--fg-3)', marginTop: 4 }}>daily snapshot · computed 05:00 · {INSIGHTS.length} actionable</div>
          </div>
          <span style={{ flex: 1 }}/>
          <button className="btn sm ghost"><Icon.Sparkles size={12}/>Recompute now</button>
        </div>

        {/* Today card */}
        <div className="panel popin" style={{ padding: 22, marginBottom: 16, background: 'linear-gradient(135deg, rgba(139,92,246,0.12) 0%, rgba(34,211,238,0.08) 50%, var(--panel-solid) 100%)', borderColor: 'rgba(139,92,246,0.3)', position: 'relative', overflow: 'hidden' }}>
          <div style={{ position: 'absolute', right: -40, top: -40, width: 200, height: 200, background: 'radial-gradient(circle, rgba(139,92,246,.35), transparent 70%)', pointerEvents: 'none' }}/>
          <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 6 }}>
            <Icon.Sparkles size={14} style={{ color: 'var(--accent-a)' }}/>
            <span className="mono" style={{ fontSize: 11, color: 'var(--accent-a)', textTransform: 'uppercase', letterSpacing: '.1em', fontWeight: 600 }}>Today we suggest</span>
          </div>
          <div style={{ fontSize: 24, fontWeight: 600, letterSpacing: '-0.015em', marginBottom: 4, maxWidth: 720 }}>
            Promote <span className="grad-text">4 documents</span>, review <span className="grad-text">7 orphans</span>, and close a coverage gap on <span className="grad-text">"parental leave EU"</span>.
          </div>
          <div style={{ fontSize: 13, color: 'var(--fg-2)', marginTop: 6, maxWidth: 720, lineHeight: 1.5 }}>
            These actions were selected based on 30-day retrieval frequency, rating distribution, and canonical compilation gaps detected by the nightly AI pass.
          </div>
          <div style={{ display: 'flex', gap: 8, marginTop: 14 }}>
            <button className="btn primary">Review promotions</button>
            <button className="btn">See gap analysis</button>
          </div>
        </div>

        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 12 }}>
          {INSIGHTS.map(ins => {
            const Ico = Icon[kindIcon[ins.kind]] || Icon.Sparkles;
            const c = kindColor[ins.kind];
            const isOpen = open === ins.id;
            return (
              <div key={ins.id} onClick={() => setOpen(isOpen ? null : ins.id)} className="panel popin" style={{
                padding: 16, cursor: 'pointer',
                borderColor: isOpen ? 'rgba(139,92,246,.35)' : undefined,
                boxShadow: isOpen ? 'var(--glow)' : undefined,
                transition: 'all .2s',
              }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 10 }}>
                  <div style={{ width: 28, height: 28, borderRadius: 7, background: `${c}15`, border: `1px solid ${c}40`, display: 'flex', alignItems: 'center', justifyContent: 'center', color: c }}>
                    <Ico size={14}/>
                  </div>
                  <span className={`pill ${ins.severity === 'high' ? 'err' : ins.severity === 'medium' ? 'warn' : ''}`} style={{ fontSize: 10 }}>{ins.severity}</span>
                  <span style={{ flex: 1 }}/>
                  <span className="mono" style={{ fontSize: 24, fontWeight: 700, color: c, letterSpacing: '-0.03em' }}>{ins.count}</span>
                </div>
                <div style={{ fontSize: 13.5, fontWeight: 500, lineHeight: 1.35, marginBottom: 4 }}>{ins.title}</div>
                <div style={{ fontSize: 12, color: 'var(--fg-2)', lineHeight: 1.45 }}>{ins.detail}</div>
                {isOpen && (
                  <div className="popin" style={{ marginTop: 12, paddingTop: 12, borderTop: '1px solid var(--hairline)' }}>
                    {ins.kind === 'promote' && (
                      <div style={{ display: 'flex', flexDirection: 'column', gap: 5 }}>
                        {['incident-response.md','deploy-rollback.md','month-close-checklist.md','msa-template-v3.md'].map((d, i) => (
                          <div key={i} style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '5px 8px', background: 'var(--bg-2)', borderRadius: 5, fontSize: 11.5 }}>
                            <Icon.File size={11}/>
                            <span className="mono" style={{ flex: 1, color: 'var(--fg-1)' }}>{d}</span>
                            <span className="mono" style={{ fontSize: 10, color: 'var(--fg-3)' }}>{82 - i*6}%</span>
                          </div>
                        ))}
                      </div>
                    )}
                    {ins.kind !== 'promote' && (
                      <div style={{ fontSize: 11.5, color: 'var(--fg-2)' }}>
                        Deep-dive with sample documents, retrieval history, and proposed resolutions.
                      </div>
                    )}
                    <button className="btn primary sm" style={{ marginTop: 10, width: '100%' }}>
                      {ins.kind === 'promote' ? 'Promote all 4' : ins.kind === 'orphan' ? 'Review orphans' : ins.kind === 'tags' ? 'Apply AI tags' : ins.kind === 'stale' ? 'Open stale docs' : 'Open gap report'}
                    </button>
                  </div>
                )}
              </div>
            );
          })}
        </div>
      </div>
    </div>
  );
}

Object.assign(window, { UsersView, KbView, LogsView, MaintenanceView, InsightsView });

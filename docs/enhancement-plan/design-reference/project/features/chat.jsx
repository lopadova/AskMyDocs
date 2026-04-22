// Chat feature — conversation list, message thread, streaming, wikilinks, citations, graph, artifacts
const { useState: uS, useEffect: uE, useRef: uR, useMemo: uM } = React;

function renderInlineMd(text) {
  // simple inline markdown → tokens (bold, code, wikilinks)
  const out = [];
  let rest = text;
  const re = /(\*\*[^*]+\*\*)|(`[^`]+`)|(\[\[[^\]]+\]\])/g;
  let m, last = 0, key = 0;
  while ((m = re.exec(text)) !== null) {
    if (m.index > last) out.push(<span key={key++}>{text.slice(last, m.index)}</span>);
    const tok = m[0];
    if (tok.startsWith('**')) out.push(<strong key={key++}>{tok.slice(2, -2)}</strong>);
    else if (tok.startsWith('`')) out.push(<code key={key++} className="mono" style={{ padding: '1px 5px', background: 'var(--bg-3)', border: '1px solid var(--panel-border)', borderRadius: 4, fontSize: '0.88em' }}>{tok.slice(1, -1)}</code>);
    else if (tok.startsWith('[[')) {
      const slug = tok.slice(2, -2);
      out.push(<WikiLink key={key++} slug={slug}/>);
    }
    last = m.index + tok.length;
  }
  if (last < text.length) out.push(<span key={key++}>{text.slice(last)}</span>);
  return out;
}

function WikiLink({ slug }) {
  const [hover, setHover] = uS(false);
  const related = RELATED_GRAPH.nodes.find(n => n.id === slug);
  return (
    <span style={{ position: 'relative', display: 'inline-block' }}
          onMouseEnter={() => setHover(true)} onMouseLeave={() => setHover(false)}>
      <a style={{
        color: 'transparent',
        backgroundImage: 'var(--grad-accent)',
        backgroundClip: 'text',
        WebkitBackgroundClip: 'text',
        borderBottom: '1px dashed rgba(139,92,246,.5)',
        cursor: 'pointer', fontWeight: 500,
      }}>[[{slug}]]</a>
      {hover && (
        <span className="panel popin" style={{
          position: 'absolute', bottom: 'calc(100% + 6px)', left: 0, zIndex: 40,
          minWidth: 280, padding: 12, fontSize: 12,
          background: 'var(--panel-solid)', boxShadow: 'var(--shadow-lg)',
        }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 6, marginBottom: 6 }}>
            <Icon.File size={13}/>
            <span className="mono" style={{ fontSize: 11, color: 'var(--fg-2)' }}>{slug}.md</span>
            {related && <span className="pill" style={{ marginLeft: 'auto', fontSize: 10 }}>{related.kind}</span>}
          </div>
          <div style={{ color: 'var(--fg-2)', lineHeight: 1.5 }}>
            Canonical document in <span style={{ color: 'var(--fg-0)' }}>hr-portal</span>. Last indexed 3 days ago · 14 chunks.
          </div>
          <div style={{ display: 'flex', gap: 6, marginTop: 8 }}>
            <button className="btn sm"><Icon.Eye size={12}/>Open</button>
            <button className="btn sm ghost"><Icon.Copy size={12}/>Copy link</button>
          </div>
        </span>
      )}
    </span>
  );
}

function renderMarkdown(md) {
  // block-level renderer: headings, lists, tables, code, callouts, paragraphs
  const lines = md.split('\n');
  const blocks = [];
  let i = 0;
  while (i < lines.length) {
    const l = lines[i];
    if (l.startsWith('```')) {
      const lang = l.slice(3).trim();
      const start = i + 1;
      let end = start;
      while (end < lines.length && !lines[end].startsWith('```')) end++;
      blocks.push({ type: 'code', lang, content: lines.slice(start, end).join('\n') });
      i = end + 1; continue;
    }
    if (l.startsWith('# '))       { blocks.push({ type: 'h1', text: l.slice(2) }); i++; continue; }
    if (l.startsWith('## '))      { blocks.push({ type: 'h2', text: l.slice(3) }); i++; continue; }
    if (l.startsWith('### '))     { blocks.push({ type: 'h3', text: l.slice(4) }); i++; continue; }
    if (l.startsWith('> [!'))     {
      const mk = l.match(/> \[!(\w+)\]/); const kind = mk ? mk[1] : 'note';
      const out = [];
      let j = i + 1;
      while (j < lines.length && lines[j].startsWith('> ')) { out.push(lines[j].slice(2)); j++; }
      blocks.push({ type: 'callout', kind, text: out.join(' ') });
      i = j; continue;
    }
    if (l.startsWith('|')) {
      const rows = [];
      while (i < lines.length && lines[i].startsWith('|')) { rows.push(lines[i]); i++; }
      blocks.push({ type: 'table', rows });
      continue;
    }
    if (/^[-*] /.test(l)) {
      const items = [];
      while (i < lines.length && /^[-*] /.test(lines[i])) { items.push(lines[i].slice(2)); i++; }
      blocks.push({ type: 'ul', items });
      continue;
    }
    if (/^\d+\.\s/.test(l)) {
      const items = [];
      while (i < lines.length && /^\d+\.\s/.test(lines[i])) { items.push(lines[i].replace(/^\d+\.\s/, '')); i++; }
      blocks.push({ type: 'ol', items });
      continue;
    }
    if (l.trim() === '') { i++; continue; }
    // paragraph
    const p = [];
    while (i < lines.length && lines[i].trim() !== '' && !/^(#{1,3} |```|> |\||[-*] |\d+\.\s)/.test(lines[i])) { p.push(lines[i]); i++; }
    blocks.push({ type: 'p', text: p.join(' ') });
  }
  return blocks.map((b, idx) => <MdBlock key={idx} block={b}/>);
}

function MdBlock({ block }) {
  const b = block;
  if (b.type === 'h1') return <h2 style={{ margin: '18px 0 10px', fontSize: 22, fontWeight: 600, letterSpacing: '-0.015em' }}>{renderInlineMd(b.text)}</h2>;
  if (b.type === 'h2') return <h3 style={{ margin: '16px 0 8px', fontSize: 17, fontWeight: 600, letterSpacing: '-0.01em' }}>{renderInlineMd(b.text)}</h3>;
  if (b.type === 'h3') return <h4 style={{ margin: '14px 0 6px', fontSize: 14, fontWeight: 600, color: 'var(--fg-1)' }}>{renderInlineMd(b.text)}</h4>;
  if (b.type === 'p')  return <p style={{ margin: '8px 0', lineHeight: 1.65, color: 'var(--fg-1)' }}>{renderInlineMd(b.text)}</p>;
  if (b.type === 'ul') return <ul style={{ margin: '8px 0', paddingLeft: 22, lineHeight: 1.7, color: 'var(--fg-1)' }}>{b.items.map((it, i) => <li key={i}>{renderInlineMd(it)}</li>)}</ul>;
  if (b.type === 'ol') return <ol style={{ margin: '8px 0', paddingLeft: 22, lineHeight: 1.7, color: 'var(--fg-1)' }}>{b.items.map((it, i) => <li key={i}>{renderInlineMd(it)}</li>)}</ol>;
  if (b.type === 'code') return <CodeBlock lang={b.lang} code={b.content}/>;
  if (b.type === 'callout') {
    const palette = { note: ['#22d3ee', 'Note'], warning: ['#f59e0b', 'Warning'], tip: ['#10b981', 'Tip'] };
    const [c, label] = palette[b.kind] || palette.note;
    return (
      <div style={{
        margin: '12px 0', padding: '10px 14px',
        background: `${c}15`, border: `1px solid ${c}40`, borderLeft: `3px solid ${c}`,
        borderRadius: 8, fontSize: 13,
      }}>
        <div style={{ fontSize: 10.5, color: c, textTransform: 'uppercase', letterSpacing: '.08em', fontWeight: 600, marginBottom: 3, fontFamily: 'var(--font-mono)' }}>{label}</div>
        <div style={{ color: 'var(--fg-1)', lineHeight: 1.55 }}>{renderInlineMd(b.text)}</div>
      </div>
    );
  }
  if (b.type === 'table') {
    const rows = b.rows.filter(r => !/^\|\s*-/.test(r));
    const parse = (r) => r.split('|').slice(1, -1).map(c => c.trim());
    const [head, ...body] = rows.map(parse);
    return (
      <div style={{ margin: '12px 0', overflow: 'auto', border: '1px solid var(--panel-border)', borderRadius: 8 }}>
        <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12.5 }}>
          <thead>
            <tr style={{ background: 'var(--bg-2)' }}>
              {head.map((h, i) => <th key={i} style={{ textAlign: 'left', padding: '8px 12px', fontWeight: 600, color: 'var(--fg-1)', borderBottom: '1px solid var(--hairline)' }}>{renderInlineMd(h)}</th>)}
            </tr>
          </thead>
          <tbody>
            {body.map((row, i) => (
              <tr key={i} style={{ borderTop: '1px solid var(--hairline)' }}>
                {row.map((c, j) => <td key={j} style={{ padding: '8px 12px', color: 'var(--fg-1)', verticalAlign: 'top' }}>{renderInlineMd(c)}</td>)}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    );
  }
  return null;
}

function CodeBlock({ lang, code }) {
  const [copied, setCopied] = uS(false);
  return (
    <div style={{ margin: '12px 0', background: 'var(--bg-2)', border: '1px solid var(--panel-border)', borderRadius: 10, overflow: 'hidden' }}>
      <div style={{ display: 'flex', alignItems: 'center', padding: '6px 10px', borderBottom: '1px solid var(--hairline)', background: 'var(--bg-1)' }}>
        <span className="mono" style={{ fontSize: 10.5, color: 'var(--fg-3)', textTransform: 'uppercase', letterSpacing: '.06em' }}>{lang || 'text'}</span>
        <span style={{ flex: 1 }}/>
        <button className="btn sm ghost" style={{ height: 22, fontSize: 10.5 }}
                onClick={() => { navigator.clipboard?.writeText(code); setCopied(true); setTimeout(() => setCopied(false), 1200); }}>
          {copied ? <><Icon.Check size={11}/>Copied</> : <><Icon.Copy size={11}/>Copy</>}
        </button>
      </div>
      <pre className="mono" style={{ margin: 0, padding: '10px 14px', fontSize: 12.5, color: 'var(--fg-0)', overflow: 'auto', lineHeight: 1.55 }}>{code}</pre>
    </div>
  );
}

function ThinkingTrace({ steps }) {
  const [open, setOpen] = uS(false);
  return (
    <div style={{ marginBottom: 12 }}>
      <button onClick={() => setOpen(!open)} style={{
        display: 'inline-flex', alignItems: 'center', gap: 8,
        padding: '5px 10px', background: 'var(--bg-2)', border: '1px solid var(--panel-border)',
        borderRadius: 99, color: 'var(--fg-2)', fontSize: 11.5, fontFamily: 'var(--font-mono)', cursor: 'pointer',
      }}>
        <Icon.Brain size={12}/>
        Thinking · {steps.length} steps · 1.4s
        <Icon.Chevron size={11} style={{ transform: open ? 'rotate(90deg)' : 'none', transition: 'transform .15s' }}/>
      </button>
      {open && (
        <div className="popin" style={{ marginTop: 8, padding: '10px 12px', background: 'var(--bg-2)', border: '1px solid var(--panel-border)', borderRadius: 8, fontSize: 12, color: 'var(--fg-2)', lineHeight: 1.6 }}>
          {steps.map((s, i) => (
            <div key={i} style={{ display: 'flex', gap: 10, padding: '3px 0' }}>
              <span className="mono" style={{ color: 'var(--fg-3)', fontSize: 10.5, minWidth: 14 }}>{String(i+1).padStart(2, '0')}</span>
              <span>{s}</span>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

function CitationsStrip({ cites }) {
  return (
    <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6, marginTop: 10 }}>
      {cites.map((c, i) => (
        <CitationChip key={i} c={c} idx={i}/>
      ))}
    </div>
  );
}

function CitationChip({ c, idx }) {
  const [hover, setHover] = uS(false);
  return (
    <span style={{ position: 'relative' }}
          onMouseEnter={() => setHover(true)} onMouseLeave={() => setHover(false)}>
      <button style={{
        display: 'inline-flex', alignItems: 'center', gap: 6,
        padding: '4px 9px 4px 4px', background: 'var(--bg-2)', border: '1px solid var(--panel-border)',
        borderRadius: 99, cursor: 'pointer', color: 'var(--fg-1)', fontSize: 11.5,
      }}>
        <span style={{ width: 18, height: 18, borderRadius: 99, background: 'var(--grad-accent)',
                       display: 'inline-flex', alignItems: 'center', justifyContent: 'center',
                       fontSize: 10, fontWeight: 600, color: '#0a0a14', fontFamily: 'var(--font-mono)' }}>
          {idx + 1}
        </span>
        <span className="mono" style={{ fontSize: 11 }}>{c.path}</span>
        <span style={{ color: 'var(--fg-3)' }}>·</span>
        <span style={{ color: 'var(--fg-3)' }}>§{c.section}</span>
      </button>
      {hover && (
        <span className="panel popin" style={{
          position: 'absolute', bottom: 'calc(100% + 8px)', left: 0, zIndex: 40,
          width: 360, padding: 14, fontSize: 12,
          background: 'var(--panel-solid)', boxShadow: 'var(--shadow-lg)',
        }}>
          <div style={{ display: 'flex', gap: 8, alignItems: 'center', marginBottom: 8 }}>
            <span className="pill accent">Chunk {idx + 1}</span>
            <span className="mono" style={{ fontSize: 10.5, color: 'var(--fg-2)' }}>{c.project} / {c.path}</span>
          </div>
          <div style={{ color: 'var(--fg-1)', lineHeight: 1.55, fontSize: 12.5, padding: 10, background: 'var(--bg-2)', borderRadius: 6, borderLeft: '2px solid var(--accent-a)' }}>
            {c.excerpt}
          </div>
          <div style={{ display: 'flex', gap: 10, marginTop: 10, fontSize: 11, color: 'var(--fg-3)' }}>
            <span>relevance <span className="mono" style={{ color: 'var(--fg-1)' }}>{c.score}</span></span>
            <span>·</span>
            <span>tokens <span className="mono" style={{ color: 'var(--fg-1)' }}>{c.tokens}</span></span>
          </div>
        </span>
      )}
    </span>
  );
}

function RelatedGraph({ data, small }) {
  const w = small ? 360 : 480;
  const h = small ? 220 : 300;
  const cx = w / 2, cy = h / 2;
  const kindColor = { policy: '#8b5cf6', reference: '#22d3ee', decision: '#f97316', runbook: '#a3e635' };
  return (
    <svg width="100%" viewBox={`0 0 ${w} ${h}`} style={{ display: 'block' }}>
      <defs>
        <radialGradient id="nodegrad" cx="50%" cy="50%">
          <stop offset="0" stopColor="#8b5cf6" stopOpacity=".9"/>
          <stop offset="1" stopColor="#22d3ee" stopOpacity=".5"/>
        </radialGradient>
      </defs>
      {data.edges.map(([a, b], i) => {
        const na = data.nodes.find(n => n.id === a);
        const nb = data.nodes.find(n => n.id === b);
        return <line key={i} x1={cx + na.x} y1={cy + na.y} x2={cx + nb.x} y2={cy + nb.y}
                     stroke="var(--panel-border-strong)" strokeWidth="1" strokeDasharray="3 4"
                     style={{ animation: `popin .5s ${i * 80}ms both` }}/>;
      })}
      {data.nodes.map((n, i) => {
        const r = n.focus ? 22 : 14;
        const c = kindColor[n.kind] || '#8b5cf6';
        return (
          <g key={n.id} style={{ animation: `popin .5s ${i * 80}ms both`, cursor: 'pointer' }}>
            {n.focus && <circle cx={cx + n.x} cy={cy + n.y} r={r + 8} fill={c} opacity=".12"/>}
            <circle cx={cx + n.x} cy={cy + n.y} r={r}
                    fill={n.focus ? 'url(#nodegrad)' : 'var(--bg-2)'}
                    stroke={c} strokeWidth={n.focus ? 0 : 1.5}/>
            <text x={cx + n.x} y={cy + n.y + (n.focus ? 4 : 3.5)}
                  fontSize={n.focus ? 11 : 10} textAnchor="middle"
                  fill={n.focus ? '#0a0a14' : 'var(--fg-1)'}
                  className="mono" fontWeight={n.focus ? 600 : 500}>
              {n.label.split('-')[0]}
            </text>
            <text x={cx + n.x} y={cy + n.y + r + 14} fontSize="9.5"
                  textAnchor="middle" fill="var(--fg-3)" className="mono">
              {n.label}
            </text>
          </g>
        );
      })}
    </svg>
  );
}

function ChatMessage({ msg, streaming, onGraph }) {
  const isUser = msg.role === 'user';
  if (isUser) {
    return (
      <div className="popin" style={{ display: 'flex', justifyContent: 'flex-end', marginBottom: 18 }}>
        <div style={{ maxWidth: '70%', padding: '10px 14px', background: 'var(--bg-3)', border: '1px solid var(--panel-border)', borderRadius: '14px 14px 4px 14px', fontSize: 13.5, lineHeight: 1.55, color: 'var(--fg-0)' }}>
          {msg.content}
        </div>
      </div>
    );
  }
  return (
    <div className="popin" style={{ display: 'flex', gap: 12, marginBottom: 22 }}>
      <div style={{ width: 30, height: 30, borderRadius: 9, background: 'var(--grad-accent)', display: 'flex', alignItems: 'center', justifyContent: 'center', flex: '0 0 auto' }}>
        <Icon.Logo size={16}/>
      </div>
      <div style={{ flex: 1, minWidth: 0 }}>
        {msg.thinking && <ThinkingTrace steps={msg.thinking}/>}
        <div style={{ fontSize: 13.5, color: 'var(--fg-1)' }}>
          {renderMarkdown(msg.content)}
          {streaming && <span className="caret"/>}
        </div>
        {msg.citations && !streaming && <CitationsStrip cites={msg.citations}/>}
        {!streaming && (
          <div style={{ display: 'flex', alignItems: 'center', gap: 2, marginTop: 10 }}>
            <button className="btn icon sm ghost"><Icon.Copy size={12}/></button>
            <button className="btn icon sm ghost" title="Good"><span style={{ fontSize: 11 }}>👍</span></button>
            <button className="btn icon sm ghost" title="Bad"><span style={{ fontSize: 11 }}>👎</span></button>
            <button className="btn sm ghost" onClick={onGraph}><Icon.Share size={12}/>Graph</button>
            <span style={{ flex: 1 }}/>
            <span className="mono" style={{ fontSize: 10.5, color: 'var(--fg-3)' }}>claude-sonnet-4.5 · 1.2s · 1,248 tok</span>
          </div>
        )}
      </div>
    </div>
  );
}

function ChatView({ project }) {
  const [convs] = uS(CONVERSATIONS);
  const [activeConv, setActiveConv] = uS('c1');
  const [msgs, setMsgs] = uS(() => [
    { id: 1, role: 'user', content: 'Are new hires eligible for the remote work stipend, and from when?' },
    {
      id: 2, role: 'assistant', content: SAMPLE_REPLY,
      thinking: [
        'Identifying query intent: eligibility + stipend + timing for new hires.',
        'Retrieving canonical: remote-work-policy.md (score 0.94), new-hire-checklist.md (0.81), expense-reimbursement.md (0.78).',
        'Cross-checking with role-remote-eligibility matrix for classification requirements.',
        'Synthesizing structured answer with table breakdown.',
      ],
      citations: [
        { path: 'hr-portal/policies/remote-work-policy.md', section: 'Expense reimbursement', score: 0.94, tokens: 412,
          excerpt: 'Employees working remote more than 50% of the time receive a monthly stipend of €80 for internet and utilities. Hybrid 3-day arrangements receive €40/month.', project: 'hr-portal' },
        { path: 'hr-portal/onboarding/new-hire-checklist.md', section: 'Month 1 setup', score: 0.81, tokens: 284,
          excerpt: 'All new hires complete mandatory security training during week 1. Remote work requests are processed starting month 2 after manager review.', project: 'hr-portal' },
        { path: 'hr-portal/policies/expense-reimbursement.md', section: 'Stipend claims', score: 0.78, tokens: 198,
          excerpt: 'Claims must be filed monthly via the HRIS. Supporting documentation not required for fixed stipends under €100/month.', project: 'hr-portal' },
      ],
    },
  ]);
  const [draft, setDraft] = uS('');
  const [streaming, setStreaming] = uS(false);
  const [streamText, setStreamText] = uS('');
  const [focus, setFocus] = uS(false);
  const [listening, setListening] = uS(false);
  const [showGraph, setShowGraph] = uS(false);
  const threadRef = uR();

  uE(() => {
    threadRef.current?.scrollTo({ top: threadRef.current.scrollHeight, behavior: 'smooth' });
  }, [msgs, streamText]);

  const send = () => {
    if (!draft.trim() || streaming) return;
    const q = draft.trim();
    setMsgs(m => [...m, { id: Date.now(), role: 'user', content: q }]);
    setDraft('');
    setStreaming(true); setStreamText('');
    let i = 0;
    const full = 'Based on current policies and canonical documents in **[[remote-work-policy]]**, here is what applies for your case:\n\nThe relevant rules are documented in the HR portal KB. Let me walk through the key points one by one so it\'s actionable.\n\n- Manager approval is required for hybrid arrangements beyond 2 days\n- Full remote requires VP sign-off\n- Security requirements from [[data-protection]] apply to all remote devices';
    const step = () => {
      i += 3 + Math.floor(Math.random() * 4);
      setStreamText(full.slice(0, i));
      if (i < full.length) setTimeout(step, 22);
      else {
        setStreaming(false);
        setMsgs(m => [...m, {
          id: Date.now() + 1, role: 'assistant', content: full,
          thinking: ['Parsing intent.','Retrieving canonical docs from hr-portal.','Composing grounded answer.'],
          citations: [
            { path: 'hr-portal/policies/remote-work-policy.md', section: 'Intro', score: 0.92, tokens: 320, excerpt: 'ACME employees may work remotely up to 3 days per week with manager approval.', project: 'hr-portal' },
            { path: 'hr-portal/policies/data-protection.md', section: 'Remote devices', score: 0.77, tokens: 240, excerpt: 'All remote devices must comply with device encryption and VPN policy.', project: 'hr-portal' },
          ],
        }]);
        setStreamText('');
      }
    };
    setTimeout(step, 360);
  };

  const kindColor = { policy: '#8b5cf6', reference: '#22d3ee', decision: '#f97316', runbook: '#a3e635' };

  return (
    <div style={{ display: 'flex', height: '100%' }}>
      {/* Conversation list */}
      <div style={{ width: 272, flex: '0 0 272px', borderRight: '1px solid var(--hairline)', background: 'var(--bg-1)', display: 'flex', flexDirection: 'column' }}>
        <div style={{ padding: 12, display: 'flex', gap: 8 }}>
          <button className="btn primary" style={{ flex: 1 }}><Icon.Plus size={13}/>New chat</button>
          <Tooltip label="Filter"><button className="btn icon ghost"><Icon.Filter size={13}/></button></Tooltip>
        </div>
        <div style={{ padding: '0 12px 10px' }}>
          <div style={{ position: 'relative' }}>
            <Icon.Search size={12} style={{ position: 'absolute', left: 10, top: 9.5, color: 'var(--fg-3)' }}/>
            <input className="input" placeholder="Search conversations" style={{ paddingLeft: 30, height: 30, fontSize: 12 }}/>
          </div>
        </div>
        <div style={{ flex: 1, overflow: 'auto', padding: '4px 10px 10px' }}>
          <div style={{ fontSize: 10, color: 'var(--fg-3)', textTransform: 'uppercase', letterSpacing: '.08em', padding: '4px 6px', fontFamily: 'var(--font-mono)' }}>Today</div>
          {convs.filter(c => c.when.includes('ago') || c.when === 'today').map(c => {
            const p = PROJECTS.find(x => x.key === c.project);
            const isActive = activeConv === c.id;
            return (
              <button key={c.id} onClick={() => setActiveConv(c.id)} style={{
                width: '100%', display: 'flex', gap: 9, padding: '8px 10px',
                background: isActive ? 'var(--bg-3)' : 'transparent',
                border: '1px solid ' + (isActive ? 'var(--panel-border)' : 'transparent'),
                borderRadius: 8, cursor: 'pointer', marginBottom: 2, textAlign: 'left',
              }}>
                <ProjectDot p={p} size={8}/>
                <div style={{ flex: 1, minWidth: 0 }}>
                  <div style={{ fontSize: 12.5, color: 'var(--fg-0)', fontWeight: isActive ? 500 : 400, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{c.title}</div>
                  <div style={{ fontSize: 10.5, color: 'var(--fg-3)', fontFamily: 'var(--font-mono)', marginTop: 1 }}>{p.label} · {c.when}</div>
                </div>
                {c.unread && <span style={{ width: 6, height: 6, borderRadius: 99, background: 'var(--accent-b)', marginTop: 5 }}/>}
              </button>
            );
          })}
          <div style={{ fontSize: 10, color: 'var(--fg-3)', textTransform: 'uppercase', letterSpacing: '.08em', padding: '10px 6px 4px', fontFamily: 'var(--font-mono)' }}>Earlier</div>
          {convs.filter(c => !c.when.includes('ago') && c.when !== 'today').map(c => {
            const p = PROJECTS.find(x => x.key === c.project);
            return (
              <button key={c.id} style={{
                width: '100%', display: 'flex', gap: 9, padding: '8px 10px',
                background: 'transparent', border: '1px solid transparent',
                borderRadius: 8, cursor: 'pointer', marginBottom: 2, textAlign: 'left',
              }}>
                <ProjectDot p={p} size={8}/>
                <div style={{ flex: 1, minWidth: 0 }}>
                  <div style={{ fontSize: 12.5, color: 'var(--fg-1)', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{c.title}</div>
                  <div style={{ fontSize: 10.5, color: 'var(--fg-3)', fontFamily: 'var(--font-mono)', marginTop: 1 }}>{p.label} · {c.when}</div>
                </div>
              </button>
            );
          })}
        </div>
      </div>

      {/* Thread */}
      <div style={{ flex: 1, display: 'flex', flexDirection: 'column', minWidth: 0, position: 'relative' }}>
        <div style={{ padding: '12px 24px', borderBottom: '1px solid var(--hairline)', display: 'flex', alignItems: 'center', gap: 10 }}>
          <div style={{ flex: 1, minWidth: 0 }}>
            <div style={{ fontSize: 13.5, fontWeight: 500, color: 'var(--fg-0)' }}>Remote work stipend for new hires</div>
            <div style={{ fontSize: 11, color: 'var(--fg-3)', fontFamily: 'var(--font-mono)', marginTop: 2, display: 'flex', gap: 10 }}>
              <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5 }}><ProjectDot p={project} size={7}/>{project.label}</span>
              <span>·</span>
              <span>3 citations</span>
              <span>·</span>
              <span>2 messages</span>
            </div>
          </div>
          <button className="btn sm ghost" onClick={() => setShowGraph(!showGraph)}><Icon.Share size={13}/>Related graph</button>
          <button className="btn icon sm ghost"><Icon.MoreH size={14}/></button>
        </div>

        <div ref={threadRef} style={{ flex: 1, overflow: 'auto', padding: '24px 32px' }} className="grid-bg">
          <div style={{ maxWidth: 780, margin: '0 auto' }}>
            {msgs.map(m => <ChatMessage key={m.id} msg={m} onGraph={() => setShowGraph(true)}/>)}
            {streaming && (
              <ChatMessage
                msg={{ role: 'assistant', content: streamText, thinking: ['Analyzing intent…', 'Retrieving from KB…'] }}
                streaming
              />
            )}
          </div>
        </div>

        {/* Composer */}
        <div style={{ padding: '12px 24px 18px' }}>
          <div className={`glow-frame ${focus ? 'on' : ''}`} style={{
            background: 'var(--panel-solid)',
            border: '1px solid var(--panel-border-strong)',
            borderRadius: 14,
            boxShadow: focus ? 'var(--glow)' : 'var(--shadow)',
            transition: 'box-shadow .25s',
          }}>
            <div style={{ display: 'flex', gap: 6, padding: '10px 12px 2px', flexWrap: 'wrap' }}>
              <ContextChip icon="Folder" label={project.label} color={project.color}/>
              <ContextChip icon="Book" label="canonical only"/>
              <ContextChip icon="Brain" label="claude-sonnet-4.5"/>
              <span style={{ flex: 1 }}/>
              <span className="mono" style={{ fontSize: 10.5, color: 'var(--fg-3)', padding: 4 }}>/ commands · @ mentions</span>
            </div>
            <textarea
              value={draft} onChange={(e) => setDraft(e.target.value)}
              onFocus={() => setFocus(true)} onBlur={() => setFocus(false)}
              onKeyDown={(e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); } }}
              placeholder="Ask anything grounded in your knowledge base…"
              rows={2}
              style={{
                width: '100%', padding: '6px 14px 10px', background: 'transparent', border: 0, outline: 'none',
                color: 'var(--fg-0)', fontSize: 14, fontFamily: 'var(--font-sans)', resize: 'none', lineHeight: 1.5,
              }}
            />
            <div style={{ display: 'flex', alignItems: 'center', gap: 6, padding: '6px 12px 10px' }}>
              <Tooltip label="Attach"><button className="btn icon sm ghost"><Icon.Plus size={13}/></button></Tooltip>
              <Tooltip label={listening ? 'Listening…' : 'Voice input'}>
                <button onClick={() => setListening(!listening)} className="btn icon sm"
                        style={{
                          background: listening ? 'var(--grad-accent)' : 'transparent',
                          border: listening ? 0 : '1px solid transparent',
                          color: listening ? '#0a0a14' : 'var(--fg-2)',
                        }}>
                  <Icon.Mic size={13}/>
                  {listening && <span style={{
                    position: 'absolute', inset: -2, borderRadius: 8,
                    border: '2px solid var(--accent-a)',
                    animation: 'pulse 1.4s infinite',
                  }}/>}
                </button>
              </Tooltip>
              <Tooltip label="Citations mode"><button className="btn icon sm ghost"><Icon.Quote size={13}/></button></Tooltip>
              <span style={{ flex: 1 }}/>
              <span className="mono" style={{ fontSize: 10.5, color: 'var(--fg-3)' }}>
                {draft.length > 0 ? `${draft.length} chars` : 'Shift+⏎ for new line'}
              </span>
              <button className="btn primary sm" onClick={send} disabled={!draft.trim() || streaming}
                      style={{ opacity: draft.trim() && !streaming ? 1 : 0.5 }}>
                <Icon.Send size={12}/>Send <span className="kbd" style={{ background: 'rgba(10,10,20,.2)', color: '#0a0a14', borderColor: 'rgba(10,10,20,.15)' }}>⏎</span>
              </button>
            </div>
          </div>
        </div>
      </div>

      {/* Graph panel */}
      {showGraph && (
        <div className="popin" style={{ width: 400, flex: '0 0 400px', borderLeft: '1px solid var(--hairline)', background: 'var(--bg-1)', display: 'flex', flexDirection: 'column' }}>
          <div style={{ padding: '12px 16px', borderBottom: '1px solid var(--hairline)', display: 'flex', alignItems: 'center' }}>
            <Icon.Share size={14}/>
            <span style={{ marginLeft: 8, fontSize: 13, fontWeight: 500 }}>Related knowledge graph</span>
            <span style={{ flex: 1 }}/>
            <button className="btn icon sm ghost" onClick={() => setShowGraph(false)}><Icon.Close size={13}/></button>
          </div>
          <div style={{ padding: 14 }}>
            <RelatedGraph data={RELATED_GRAPH}/>
          </div>
          <div style={{ padding: '10px 16px', borderTop: '1px solid var(--hairline)' }}>
            <div style={{ fontSize: 10.5, color: 'var(--fg-3)', textTransform: 'uppercase', letterSpacing: '.08em', fontFamily: 'var(--font-mono)', marginBottom: 8 }}>Legend</div>
            <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
              {Object.entries(kindColor).map(([k, c]) => (
                <div key={k} style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 11.5, color: 'var(--fg-2)' }}>
                  <span style={{ width: 10, height: 10, borderRadius: 99, background: c }}/>
                  <span className="mono">{k}</span>
                </div>
              ))}
            </div>
          </div>
          <div style={{ flex: 1, overflow: 'auto', padding: '10px 16px' }}>
            <div style={{ fontSize: 10.5, color: 'var(--fg-3)', textTransform: 'uppercase', letterSpacing: '.08em', fontFamily: 'var(--font-mono)', marginBottom: 8 }}>Nodes · {RELATED_GRAPH.nodes.length}</div>
            {RELATED_GRAPH.nodes.map(n => (
              <div key={n.id} style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '6px 8px', borderRadius: 6, cursor: 'pointer' }}
                   onMouseOver={(e) => e.currentTarget.style.background = 'var(--bg-3)'}
                   onMouseOut={(e) => e.currentTarget.style.background = 'transparent'}>
                <span style={{ width: 8, height: 8, borderRadius: 99, background: kindColor[n.kind] }}/>
                <span className="mono" style={{ fontSize: 11.5, color: n.focus ? 'var(--fg-0)' : 'var(--fg-1)', fontWeight: n.focus ? 600 : 400 }}>{n.label}</span>
                {n.focus && <span className="pill accent" style={{ marginLeft: 'auto', fontSize: 9.5 }}>focus</span>}
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}

function ContextChip({ icon, label, color }) {
  const Ico = Icon[icon];
  return (
    <span style={{
      display: 'inline-flex', alignItems: 'center', gap: 5,
      padding: '3px 8px', background: 'var(--bg-3)', border: '1px solid var(--panel-border)',
      borderRadius: 99, fontSize: 11, color: 'var(--fg-1)',
    }}>
      <Ico size={11} style={{ color: color || 'var(--fg-2)' }}/>
      <span>{label}</span>
    </span>
  );
}

Object.assign(window, { ChatView });

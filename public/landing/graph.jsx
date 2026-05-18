/* Animated canonical knowledge graph (Hero centerpiece)
 * 9 node types, edges drawn over time, pulse + hovered tooltip
 */
const { useState, useEffect, useRef, useMemo } = React;

const NODE_TYPES = {
  project:        { color: "#7af2c8", label: "project" },
  module:         { color: "#8ab4ff", label: "module" },
  decision:       { color: "#c4a6ff", label: "decision" },
  runbook:        { color: "#ffd166", label: "runbook" },
  standard:       { color: "#7af2c8", label: "standard" },
  incident:       { color: "#ff8a65", label: "incident" },
  integration:    { color: "#8ab4ff", label: "integration" },
  "domain-concept": { color: "#c4a6ff", label: "domain-concept" },
  "rejected-approach": { color: "#ff8a65", label: "rejected-approach" },
};

// Hand-placed for nice composition (in stage coords, sized via viewBox 800x420)
const NODES = [
  { id: "p1", type: "project",          x: 400, y: 210, label: "askmydoc/api", size: 18 },
  { id: "m1", type: "module",           x: 235, y: 145, label: "retrieval-svc" },
  { id: "m2", type: "module",           x: 565, y: 145, label: "ingestion" },
  { id: "m3", type: "module",           x: 320, y: 305, label: "promotion" },
  { id: "m4", type: "module",           x: 480, y: 305, label: "mcp-server" },
  { id: "d1", type: "decision",         x: 130, y: 90,  label: "ADR-0003: human-gated" },
  { id: "d2", type: "decision",         x: 670, y: 90,  label: "ADR-0007: hybrid-search" },
  { id: "r1", type: "runbook",          x: 90,  y: 230, label: "rotate-embeddings" },
  { id: "s1", type: "standard",         x: 710, y: 230, label: "tenant-isolation" },
  { id: "i1", type: "incident",         x: 175, y: 360, label: "INC-2025-04 cross-tenant" },
  { id: "in1",type: "integration",      x: 625, y: 360, label: "Claude Desktop / MCP" },
  { id: "c1", type: "domain-concept",   x: 410, y: 50,  label: "canonical-node" },
  { id: "x1", type: "rejected-approach",x: 410, y: 380, label: "naive RAG (vec only)" },
];

const EDGES = [
  { f: "m1", t: "p1", kind: "implements" },
  { f: "m2", t: "p1", kind: "implements" },
  { f: "m3", t: "p1", kind: "implements" },
  { f: "m4", t: "p1", kind: "implements" },
  { f: "d1", t: "m3", kind: "decision_for" },
  { f: "d2", t: "m1", kind: "decision_for" },
  { f: "r1", t: "m1", kind: "documented_by" },
  { f: "s1", t: "m2", kind: "affects" },
  { f: "i1", t: "m3", kind: "related_to" },
  { f: "in1",t: "m4", kind: "uses" },
  { f: "c1", t: "p1", kind: "related_to" },
  { f: "x1", t: "m1", kind: "supersedes", rejected: true },
  { f: "m1", t: "m2", kind: "depends_on" },
  { f: "m3", t: "m2", kind: "depends_on" },
];

function KnowledgeGraph({ floatingLabels }) {
  const labels = {
    nodes:     (floatingLabels && floatingLabels.nodes)     || "nodes",
    edges:     (floatingLabels && floatingLabels.edges)     || "edges",
    rejected:  (floatingLabels && floatingLabels.rejected)  || "rejected",
    expansion: (floatingLabels && floatingLabels.expansion) || "expansion",
  };
  const [t, setT] = useState(0);
  const [hover, setHover] = useState(null);
  const stageRef = useRef(null);

  // Animation tick
  useEffect(() => {
    let raf;
    let start = performance.now();
    const tick = (now) => {
      setT(((now - start) / 1000) % 60);
      raf = requestAnimationFrame(tick);
    };
    raf = requestAnimationFrame(tick);
    return () => cancelAnimationFrame(raf);
  }, []);

  const nodeById = useMemo(() => Object.fromEntries(NODES.map((n) => [n.id, n])), []);
  const W = 800, H = 420;

  return (
    <div className="hc-stage" ref={stageRef}>
      <div className="hc-pillbar">
        <div className="hc-pill active">canonical graph</div>
        <div className="hc-pill">tree</div>
        <div className="hc-pill">audit</div>
      </div>

      <svg viewBox={`0 0 ${W} ${H}`} preserveAspectRatio="xMidYMid meet">
        <defs>
          <radialGradient id="hgGlow" cx="50%" cy="50%" r="50%">
            <stop offset="0%" stopColor="#7af2c8" stopOpacity="0.55" />
            <stop offset="100%" stopColor="#7af2c8" stopOpacity="0" />
          </radialGradient>
          <linearGradient id="edgeGrad" x1="0" y1="0" x2="1" y2="0">
            <stop offset="0" stopColor="#8ab4ff" stopOpacity="0.7" />
            <stop offset="1" stopColor="#7af2c8" stopOpacity="0.7" />
          </linearGradient>
          <linearGradient id="edgeReject" x1="0" y1="0" x2="1" y2="0">
            <stop offset="0" stopColor="#ff8a65" stopOpacity="0.7" />
            <stop offset="1" stopColor="#ff8a65" stopOpacity="0.2" />
          </linearGradient>
          <filter id="ng" x="-50%" y="-50%" width="200%" height="200%">
            <feGaussianBlur stdDeviation="2" />
          </filter>
        </defs>

        {/* Background subtle dot field */}
        <g opacity="0.5">
          {Array.from({ length: 16 }).map((_, i) =>
            Array.from({ length: 9 }).map((_, j) => (
              <circle
                key={`d-${i}-${j}`}
                cx={(i + 0.5) * (W / 16)}
                cy={(j + 0.5) * (H / 9)}
                r="0.6"
                fill="#2a2f3a"
              />
            ))
          )}
        </g>

        {/* Edges */}
        {EDGES.map((e, idx) => {
          const a = nodeById[e.f];
          const b = nodeById[e.t];
          if (!a || !b) return null;
          const dx = b.x - a.x, dy = b.y - a.y;
          const len = Math.hypot(dx, dy);
          // animate dash to give a "flow" feeling
          const phase = (t * 22 + idx * 5) % 40;
          const dash = e.rejected ? "3 5" : "1 0";
          return (
            <g key={`e-${idx}`}>
              <line
                x1={a.x} y1={a.y} x2={b.x} y2={b.y}
                stroke={e.rejected ? "url(#edgeReject)" : "url(#edgeGrad)"}
                strokeWidth={e.rejected ? 1.2 : 1.4}
                strokeDasharray={e.rejected ? "3 4" : "1 0"}
                opacity={e.rejected ? 0.55 : 0.65}
              />
              {!e.rejected && (
                <circle
                  cx={a.x + (dx * ((phase) / 40))}
                  cy={a.y + (dy * ((phase) / 40))}
                  r="1.8"
                  fill="#7af2c8"
                  opacity="0.9"
                />
              )}
            </g>
          );
        })}

        {/* Nodes */}
        {NODES.map((n, idx) => {
          const meta = NODE_TYPES[n.type];
          const baseR = n.size || 9;
          const pulse = 1 + Math.sin(t * 1.6 + idx * 0.6) * 0.12;
          const r = baseR * pulse;
          const isHover = hover && hover.id === n.id;
          const isRejected = n.type === "rejected-approach";
          return (
            <g
              key={n.id}
              transform={`translate(${n.x} ${n.y})`}
              style={{ cursor: "default" }}
              onMouseEnter={() => setHover(n)}
              onMouseLeave={() => setHover((cur) => (cur && cur.id === n.id ? null : cur))}
            >
              {/* glow */}
              <circle r={r * 2.6} fill="url(#hgGlow)" opacity={0.55} style={{ display: n.type === "project" ? "block" : isHover ? "block" : "none" }} />
              {/* outer ring */}
              <circle r={r + 4} fill="none" stroke={meta.color} strokeOpacity={0.18} strokeWidth="1" />
              {/* core */}
              <circle
                r={r}
                fill={isRejected ? "#241612" : "#0e1015"}
                stroke={meta.color}
                strokeWidth={n.type === "project" ? 2 : 1.4}
                strokeDasharray={isRejected ? "2 2" : "0"}
              />
              <circle r={r * 0.42} fill={meta.color} opacity={isRejected ? 0.45 : 0.9} />

              {/* label */}
              <text
                x={0}
                y={r + 16}
                textAnchor="middle"
                fontFamily="Geist Mono, JetBrains Mono, monospace"
                fontSize="9.5"
                fill={isHover ? "#f4f5f8" : "#7a808c"}
                style={{
                  textDecoration: isRejected ? "line-through" : "none",
                }}
              >
                {n.label}
              </text>
            </g>
          );
        })}
      </svg>

      {hover && (() => {
        const meta = NODE_TYPES[hover.type];
        const edgesIn = EDGES.filter((e) => e.t === hover.id);
        const edgesOut = EDGES.filter((e) => e.f === hover.id);
        // position tooltip near node, in pixel coords (stage size)
        const stage = stageRef.current;
        const rect = stage ? stage.getBoundingClientRect() : { width: 600, height: 460 };
        const left = (hover.x / 800) * rect.width;
        const top = (hover.y / 420) * rect.height;
        const tipStyle = {
          left: Math.min(rect.width - 220, Math.max(8, left + 14)),
          top:  Math.min(rect.height - 90, Math.max(8, top - 30)),
        };
        return (
          <div className="hc-tip" style={tipStyle}>
            <div className="tt-type" style={{ color: meta.color }}>{hover.type}</div>
            <div>{hover.label}</div>
            <div className="tt-edge">
              {edgesIn.length} incoming · {edgesOut.length} outgoing
            </div>
          </div>
        );
      })()}

      <div className="hc-floating">
        <span><b>13</b> {labels.nodes}</span>
        <span><b>14</b> {labels.edges}</span>
        <span style={{ color: "var(--coral)" }}><b>1</b> {labels.rejected}</span>
        <span><b>1-hop</b> {labels.expansion}</span>
      </div>
    </div>
  );
}

window.KnowledgeGraph = KnowledgeGraph;
window.NODE_TYPES = NODE_TYPES;

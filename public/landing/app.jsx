/* AskMyDoc — landing page (i18n-enabled)
 * Languages: it, en, fr, de, es — switched live via top-right selector.
 * Translations live in i18n.js (window.I18N + window.LANGS).
 */
const { useState, useEffect, useRef, useMemo, createContext, useContext, Fragment } = React;

/* ---------- i18n context ---------------------------------------------- */
const I18nCtx = createContext({ t: () => "", lang: "it", setLang: () => {} });

const STORAGE_KEY = "askmydoc.landing.lang";

function detectInitialLang() {
  try {
    const saved = localStorage.getItem(STORAGE_KEY);
    if (saved && window.I18N[saved]) return saved;
  } catch (_) { /* localStorage unavailable */ }
  const supported = window.LANGS.map((l) => l.code);
  const nav = (navigator.language || "it").slice(0, 2).toLowerCase();
  return supported.includes(nav) ? nav : "it";
}

function getPath(obj, path) {
  return path.split(".").reduce((acc, key) => (acc == null ? undefined : acc[key]), obj);
}

function I18nProvider({ children }) {
  const [lang, setLangState] = useState(detectInitialLang);

  const setLang = (next) => {
    setLangState(next);
    try { localStorage.setItem(STORAGE_KEY, next); } catch (_) { /* noop */ }
  };

  useEffect(() => {
    const dict = window.I18N[lang];
    if (!dict) return;
    document.documentElement.lang = dict.htmlLang || lang;
    if (dict.pageTitle) document.title = dict.pageTitle;
    const meta = document.querySelector('meta[name="description"]');
    if (meta && dict.metaDescription) meta.setAttribute("content", dict.metaDescription);
  }, [lang]);

  const t = (path, fallback = "") => {
    const v = getPath(window.I18N[lang], path);
    return v == null ? fallback : v;
  };

  const value = { t, lang, setLang, dict: window.I18N[lang] };
  return <I18nCtx.Provider value={value}>{children}</I18nCtx.Provider>;
}

function useI18n() { return useContext(I18nCtx); }

/* Render HTML-bearing strings safely (controlled content, not user input) */
function THtml({ path, as: Tag = "span", className, style }) {
  const { t } = useI18n();
  return (
    <Tag
      className={className}
      style={style}
      dangerouslySetInnerHTML={{ __html: t(path) }}
    />
  );
}

/* ---------- shared icons ----------------------------------------------- */
const Icon = {
  Arrow: (p) => (
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" {...p}>
      <path d="M5 12h14M13 6l6 6-6 6" />
    </svg>
  ),
  Github: (p) => (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" {...p}>
      <path d="M12 .5a12 12 0 0 0-3.8 23.4c.6.1.8-.3.8-.6v-2.2c-3.3.7-4-1.6-4-1.6-.5-1.4-1.3-1.8-1.3-1.8-1.1-.7.1-.7.1-.7 1.2.1 1.8 1.2 1.8 1.2 1.1 1.8 2.8 1.3 3.5 1 .1-.8.4-1.3.8-1.6-2.7-.3-5.5-1.3-5.5-6 0-1.3.5-2.4 1.2-3.2-.1-.3-.5-1.6.1-3.2 0 0 1-.3 3.3 1.2a11.5 11.5 0 0 1 6 0c2.3-1.5 3.3-1.2 3.3-1.2.6 1.6.2 2.9.1 3.2.8.8 1.2 1.9 1.2 3.2 0 4.7-2.8 5.7-5.5 6 .4.4.8 1.1.8 2.3v3.4c0 .3.2.7.8.6A12 12 0 0 0 12 .5z"/>
    </svg>
  ),
  Search: (p) => (
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" {...p}>
      <circle cx="11" cy="11" r="7" /><path d="M21 21l-4.3-4.3" />
    </svg>
  ),
  Graph: (p) => (
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" {...p}>
      <circle cx="6" cy="6" r="2.2"/><circle cx="18" cy="6" r="2.2"/><circle cx="12" cy="18" r="2.2"/>
      <path d="M7.8 7.2L11 16.2M16.2 7.2L13 16.2M8.2 6h7.6"/>
    </svg>
  ),
  Layers: (p) => (
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" {...p}>
      <path d="M12 3l9 5-9 5-9-5 9-5z"/><path d="M3 13l9 5 9-5"/><path d="M3 18l9 5 9-5"/>
    </svg>
  ),
  Shield: (p) => (
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" {...p}>
      <path d="M12 2l8 3v6c0 5-3.5 9-8 11-4.5-2-8-6-8-11V5l8-3z"/>
    </svg>
  ),
  Cube: (p) => (
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" {...p}>
      <path d="M12 3l8 4.5v9L12 21l-8-4.5v-9L12 3z"/><path d="M12 12l8-4.5M12 12v9M12 12L4 7.5"/>
    </svg>
  ),
  Plug: (p) => (
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" {...p}>
      <path d="M9 2v6M15 2v6M7 8h10v3a5 5 0 1 1-10 0V8zM12 16v6"/>
    </svg>
  ),
  Lock: (p) => (
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" {...p}>
      <rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V8a4 4 0 1 1 8 0v3"/>
    </svg>
  ),
  Sparkle: (p) => (
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" {...p}>
      <path d="M12 3l1.7 5.3L19 10l-5.3 1.7L12 17l-1.7-5.3L5 10l5.3-1.7L12 3zM19 17l.8 2.2 2.2.8-2.2.8L19 23l-.8-2.2-2.2-.8 2.2-.8L19 17z"/>
    </svg>
  ),
  Book: (p) => (
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" {...p}>
      <path d="M4 4.5A2.5 2.5 0 0 1 6.5 2H20v17H6.5A2.5 2.5 0 0 0 4 21.5v-17z"/><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/>
    </svg>
  ),
  Support: (p) => (
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" {...p}>
      <path d="M21 11.5a8.4 8.4 0 0 1-8.5 8.5L4 21l1-3.5A8.5 8.5 0 1 1 21 11.5z"/>
    </svg>
  ),
  Scale: (p) => (
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" {...p}>
      <path d="M12 3v18M5 7h14M5 7l-3 7a4 4 0 0 0 6 0L5 7zM19 7l3 7a4 4 0 0 1-6 0L19 7z"/>
    </svg>
  ),
  Brain: (p) => (
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" {...p}>
      <path d="M9 3a3 3 0 0 0-3 3v2a3 3 0 0 0-3 3v2a3 3 0 0 0 3 3v2a3 3 0 0 0 3 3"/>
      <path d="M15 3a3 3 0 0 1 3 3v2a3 3 0 0 1 3 3v2a3 3 0 0 1-3 3v2a3 3 0 0 1-3 3"/>
      <path d="M12 6v12"/>
    </svg>
  ),
  Globe: (p) => (
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" {...p}>
      <circle cx="12" cy="12" r="9"/>
      <path d="M3 12h18M12 3c2.5 3 2.5 15 0 18M12 3c-2.5 3-2.5 15 0 18"/>
    </svg>
  ),
};

/* ---------- Brand mark ------------------------------------------------- */
function BrandMark() {
  return (
    <svg width="22" height="22" viewBox="0 0 24 24" fill="none">
      <circle cx="12" cy="12" r="10.5" stroke="#7af2c8" strokeOpacity="0.4" />
      <circle cx="12" cy="12" r="2" fill="#7af2c8" />
      <circle cx="5" cy="7" r="1.6" fill="#0e1015" stroke="#7af2c8" strokeWidth="1.2"/>
      <circle cx="19" cy="7" r="1.6" fill="#0e1015" stroke="#8ab4ff" strokeWidth="1.2"/>
      <circle cx="6" cy="18" r="1.6" fill="#0e1015" stroke="#c4a6ff" strokeWidth="1.2"/>
      <circle cx="19" cy="17" r="1.6" fill="#0e1015" stroke="#ff8a65" strokeWidth="1.2"/>
      <line x1="6.5" y1="8" x2="11" y2="11" stroke="#7af2c8" strokeOpacity="0.4" strokeWidth="0.8"/>
      <line x1="17.5" y1="8" x2="13" y2="11" stroke="#8ab4ff" strokeOpacity="0.4" strokeWidth="0.8"/>
      <line x1="7" y1="17" x2="11" y2="13" stroke="#c4a6ff" strokeOpacity="0.4" strokeWidth="0.8"/>
      <line x1="17.5" y1="16" x2="13" y2="13" stroke="#ff8a65" strokeOpacity="0.4" strokeWidth="0.4" strokeDasharray="1 1"/>
    </svg>
  );
}

/* ---------- Language switcher ----------------------------------------- */
function LanguageSwitcher({ inNav = false }) {
  const { t, lang, setLang } = useI18n();
  const [open, setOpen] = useState(false);
  const [activeIdx, setActiveIdx] = useState(0);
  const ref = useRef(null);
  const buttonRef = useRef(null);
  const optionRefs = useRef([]);
  const langs = window.LANGS;

  useEffect(() => {
    if (!open) return;
    const onDoc = (e) => {
      if (ref.current && !ref.current.contains(e.target)) setOpen(false);
    };
    const onEsc = (e) => {
      if (e.key === "Escape") {
        setOpen(false);
        buttonRef.current?.focus();
      }
    };
    document.addEventListener("mousedown", onDoc);
    document.addEventListener("keydown", onEsc);
    return () => {
      document.removeEventListener("mousedown", onDoc);
      document.removeEventListener("keydown", onEsc);
    };
  }, [open]);

  useEffect(() => {
    if (!open) return;
    const idx = langs.findIndex((l) => l.code === lang);
    setActiveIdx(idx >= 0 ? idx : 0);
  }, [open, lang, langs]);

  useEffect(() => {
    if (open) optionRefs.current[activeIdx]?.focus();
  }, [open, activeIdx]);

  const onListKeyDown = (e) => {
    if (e.key === "ArrowDown") {
      e.preventDefault();
      setActiveIdx((i) => (i + 1) % langs.length);
    } else if (e.key === "ArrowUp") {
      e.preventDefault();
      setActiveIdx((i) => (i - 1 + langs.length) % langs.length);
    } else if (e.key === "Home") {
      e.preventDefault();
      setActiveIdx(0);
    } else if (e.key === "End") {
      e.preventDefault();
      setActiveIdx(langs.length - 1);
    } else if (e.key === "Enter" || e.key === " ") {
      e.preventDefault();
      setLang(langs[activeIdx].code);
      setOpen(false);
      buttonRef.current?.focus();
    }
  };

  const current = langs.find((l) => l.code === lang) || langs[0];
  const switcherLabel = t("langSwitch", "Language");

  return (
    <div className={`lang-switcher ${inNav ? "in-nav" : ""}`} ref={ref}>
      <button
        type="button"
        ref={buttonRef}
        className={`lang-button ${open ? "open" : ""}`}
        aria-haspopup="listbox"
        aria-expanded={open}
        aria-label={switcherLabel}
        onClick={() => setOpen((v) => !v)}
      >
        <Icon.Globe />
        <span className="label-name" aria-hidden="true">{current.flag}</span>
        <span className="label-name">{current.code.toUpperCase()}</span>
        <span className="chev" aria-hidden="true" />
      </button>
      {open && (
        <ul
          className="lang-menu"
          role="listbox"
          aria-label={switcherLabel}
          aria-activedescendant={`lang-opt-${langs[activeIdx].code}`}
          onKeyDown={onListKeyDown}
        >
          {langs.map((l, i) => (
            <li key={l.code} role="presentation">
              <button
                id={`lang-opt-${l.code}`}
                type="button"
                role="option"
                ref={(el) => (optionRefs.current[i] = el)}
                aria-selected={l.code === lang}
                tabIndex={i === activeIdx ? 0 : -1}
                className={l.code === lang ? "active" : ""}
                onClick={() => { setLang(l.code); setOpen(false); buttonRef.current?.focus(); }}
                onFocus={() => setActiveIdx(i)}
              >
                <span className="flag" aria-hidden="true">{l.flag}</span>
                <span>{l.name}</span>
                <span className="code" aria-hidden="true">{l.code}</span>
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

/* ---------- Nav -------------------------------------------------------- */
function Nav() {
  const { t } = useI18n();
  return (
    <header className="nav">
      <div className="container nav-inner">
        <a href="#" className="brand">
          <BrandMark />
          <span>AskMyDoc</span>
          <span className="kbd" style={{ marginLeft: 4 }}>v1.4</span>
        </a>
        <nav className="nav-links">
          <a href="#features">{t("nav.product")}</a>
          <a href="#how">{t("nav.how")}</a>
          <a href="#security">{t("nav.security")}</a>
          <a href="#usecases" className="hide-md">{t("nav.usecases")}</a>
          <a href="#faq" className="hide-md">{t("nav.faq")}</a>
          <a href="#docs">{t("nav.docs")}</a>
        </nav>
        <div className="nav-cta">
          <a className="btn btn-link hide-sm" href="#github"><Icon.Github /> {t("nav.github")}</a>
          <a className="btn btn-ghost hide-sm" href="#docs">{t("nav.signin")}</a>
          <LanguageSwitcher inNav />
          <a className="btn btn-primary" href="#demo">{t("nav.bookdemo")} <Icon.Arrow /></a>
        </div>
      </div>
    </header>
  );
}

/* ---------- Hero ------------------------------------------------------- */
function Hero() {
  const { t, dict } = useI18n();
  return (
    <section className="hero">
      <div className="hero-grid-bg" />
      <div className="hero-glow" />
      <div className="container hero-row">
        <div className="eyebrow">
          <span className="dot" />
          <span>{t("hero.eyebrow")}</span>
        </div>

        <THtml as="h1" path="hero.titleHtml" style={{ marginTop: 28 }} />

        <p className="hero-sub">{t("hero.sub")}</p>

        <div className="hero-cta">
          <a className="btn btn-primary btn-lg" href="#demo">{t("hero.ctaPrimary")} <Icon.Arrow /></a>
          <a className="btn btn-ghost btn-lg" href="#docs">{t("hero.ctaSecondary")}</a>
        </div>

        <div className="hero-meta">
          <span>{t("hero.metaProviders")}</span>
          <span className="sep">·</span>
          <span>{t("hero.metaDb")}</span>
          <span className="sep">·</span>
          <span>{t("hero.metaStack")}</span>
        </div>

        <div className="hero-canvas">
          <div className="hc-titlebar">
            <div className="hc-dot" />
            <div className="hc-dot" />
            <div className="hc-dot" />
            <span className="hc-title">askmydoc.app/admin — kb/canonical</span>
            <div className="hc-tab-meta">
              <span>{t("hero.graphProject")}: <b>askmydoc/api</b></span>
              <span>{t("hero.graphTenant")}: <b>acme-prod</b></span>
              <span style={{ color: "var(--mint)" }}>● {t("hero.graphLive")}</span>
            </div>
          </div>

          <div className="hc-body">
            {/* Left: node types legend */}
            <div className="hc-side">
              <div className="hc-side-h">{t("graphPanel.nodeTypesHeader")}</div>
              {Object.entries(NODE_TYPES).map(([k, v]) => (
                <div className="hc-row" key={k}>
                  <span className="swatch" style={{ background: v.color }} />
                  <span>{v.label}</span>
                  <span className="num">{
                    {"project":1,"module":4,"decision":2,"runbook":1,"standard":1,"incident":1,"integration":1,"domain-concept":1,"rejected-approach":1}[k]
                  }</span>
                </div>
              ))}
            </div>

            {/* Center: graph stage */}
            <KnowledgeGraph
              floatingLabels={{
                nodes: t("graphPanel.floatingNodes"),
                edges: t("graphPanel.floatingEdges"),
                rejected: t("graphPanel.floatingRejected"),
                expansion: t("graphPanel.floatingExpansion"),
              }}
            />

            {/* Right: edge log */}
            <div className="hc-side right">
              <div className="hc-side-h">{t("graphPanel.edgesHeader")}</div>
              {[
                ["depends_on","#7af2c8"],
                ["uses","#8ab4ff"],
                ["implements","#7af2c8"],
                ["related_to","#c4a6ff"],
                ["supersedes","#ff8a65"],
                ["invalidated_by","#ff8a65"],
                ["decision_for","#c4a6ff"],
                ["documented_by","#ffd166"],
                ["affects","#8ab4ff"],
                ["owned_by","#7a808c"],
              ].map(([k, c]) => (
                <div className="hc-row" key={k}>
                  <span className="swatch" style={{ background: c }} />
                  <span>{k}</span>
                </div>
              ))}
              <div className="hc-side-h" style={{ marginTop: 14 }}>{t("graphPanel.graphExpansionHeader")}</div>
              <div className="hc-row muted">{t("graphPanel.row1hop")}</div>
              <div className="hc-row muted">{t("graphPanel.rowRejectedInj")}</div>
              <div className="hc-row muted">{t("graphPanel.rowCompositeFk")}</div>
            </div>
          </div>
        </div>
      </div>
    </section>
  );
}

/* ---------- Trust strip ------------------------------------------------ */
function TrustStrip() {
  const items = [
    "PHP / Laravel 13", "PostgreSQL 16", "pgvector 0.7",
    "React 19 + TanStack", "MCP 0.7", "OpenAI", "Anthropic", "Gemini",
    "OpenRouter", "Regolo", "Spatie Permissions", "PHPUnit · Vitest · Playwright",
  ];
  const track = [...items, ...items];
  return (
    <div className="marquee">
      <div className="marquee-track">
        {track.map((it, i) => (
          <div key={i} className="marquee-item">
            <svg width="6" height="6" viewBox="0 0 6 6"><circle cx="3" cy="3" r="2" fill="#7af2c8" opacity="0.6"/></svg>
            {it}
          </div>
        ))}
      </div>
    </div>
  );
}

/* ---------- Problem / Solution + Retrieval demo ----------------------- */
function ProblemSolution() {
  const { t } = useI18n();
  return (
    <section className="section-pad" id="problem">
      <div className="container">
        <div className="section-head">
          <div className="eyebrow"><span className="dot" /> {t("problem.eyebrow")}</div>
          <THtml as="h2" path="problem.titleHtml" />
          <THtml as="p" path="problem.subHtml" style={{ fontSize: 17, color: "var(--ink-2)", maxWidth: 640 }} />
        </div>

        <div className="split">
          <div>
            <div className="ps-card bad">
              <div className="ps-head">{t("problem.badHead")}</div>
              <h3>{t("problem.badTitle")}</h3>
              <p>{t("problem.badBody")}</p>
            </div>
            <div className="ps-card good">
              <div className="ps-head">{t("problem.goodHead")}</div>
              <h3>{t("problem.goodTitle")}</h3>
              <THtml as="p" path="problem.goodBodyHtml" />
            </div>
          </div>

          <RetrievalDemo />
        </div>
      </div>
    </section>
  );
}

function RetrievalDemo() {
  const { t, lang } = useI18n();
  const fullQuery = t("retrieval.query");
  const [typed, setTyped] = useState("");
  const [stage, setStage] = useState(0);
  // 0 = typing, 1 = pipeline filling, 2 = answer

  // Reset typing when language changes (query text differs per locale).
  useEffect(() => { setTyped(""); setStage(0); }, [lang]);

  useEffect(() => {
    if (stage === 0) {
      if (typed.length < fullQuery.length) {
        const id = setTimeout(() => setTyped(fullQuery.slice(0, typed.length + 1)), 38);
        return () => clearTimeout(id);
      } else {
        const id = setTimeout(() => setStage(1), 400);
        return () => clearTimeout(id);
      }
    }
    if (stage === 1) {
      const id = setTimeout(() => setStage(2), 1500);
      return () => clearTimeout(id);
    }
    if (stage === 2) {
      const id = setTimeout(() => { setTyped(""); setStage(0); }, 6500);
      return () => clearTimeout(id);
    }
  }, [stage, typed, fullQuery]);

  const PIPE = [
    {
      head: t("retrieval.pipeVector"),
      w: "0.6",
      rows: [
        ["auth/runbook.md#refresh", 88],
        ["adr-0011-token-rotation", 81],
        ["incident/INC-2025-04",     74],
      ],
    },
    {
      head: t("retrieval.pipeKeyword"),
      w: "0.3",
      rows: [
        ["refresh_token strategy",  79],
        ["tenant acme-prod auth",   71],
        ["sanctum guard config",    65],
      ],
    },
    {
      head: t("retrieval.pipeRerank"),
      w: "0.1",
      rows: [
        ["runbook headers match",   92],
        ["section: refresh-flow",   88],
        ["rejected: long-lived JWT",55],
      ],
    },
  ];

  const fillLevel = stage >= 1 ? 1 : 0;

  return (
    <div className="retrieval">
      <div className="retrieval-head">
        <span className="mono">{t("retrieval.headPath")}</span>
        <span style={{ color: "var(--ink-4)" }}>·</span>
        <span className="mono">{t("retrieval.headFusion")}</span>
        <span className="live" style={{ marginLeft: "auto" }}>
          <span className="pulse" /> {t("retrieval.live")}
        </span>
      </div>

      <div className="retrieval-body">
        <div className="q-input">
          <span className="q-prompt">›</span>
          <span className="q-text">{typed}{stage === 0 && <span className="q-cursor" />}</span>
        </div>

        <div className="pipeline">
          {PIPE.map((col, ci) => (
            <div className="pipe-col" key={ci}>
              <div className="pc-head">
                <span>{col.head}</span>
                <span className="w">w={col.w}</span>
              </div>
              {col.rows.map((r, ri) => {
                const delay = ci * 80 + ri * 120;
                const targetPct = (r[1] / 100) * fillLevel;
                return (
                  <div className="pipe-row" key={ri}>
                    <span style={{ flex: 1, overflow: "hidden", textOverflow: "ellipsis", whiteSpace: "nowrap" }}>
                      {r[0]}
                    </span>
                    <span className="bar">
                      <i style={{
                        width: `${targetPct * 100}%`,
                        transition: `width .8s cubic-bezier(.2,.6,.2,1) ${delay}ms`,
                      }} />
                    </span>
                    <span className="score">{Math.round(r[1] * fillLevel)}</span>
                  </div>
                );
              })}
            </div>
          ))}
        </div>

        <div className="answer" style={{ opacity: stage === 2 ? 1 : 0.35, transition: "opacity .5s" }}>
          <div className="answer-head">
            <span>{t("retrieval.answerHead")}</span>
            <span style={{ marginLeft: "auto", color: "var(--ink-4)" }}>
              {stage === 2 ? t("retrieval.answerMetaDone") : t("retrieval.answerMetaWaiting")}
            </span>
          </div>
          <THtml as="p" path="retrieval.answerBodyHtml" />
          <div className="rejected-line">
            <span className="x">✕</span>
            <THtml as="span" path="retrieval.rejectedHtml" />
          </div>
        </div>
      </div>
    </div>
  );
}

/* ---------- Feature grid ---------------------------------------------- */
function Features() {
  const { t, dict } = useI18n();
  const iconOrder = [Icon.Search, Icon.Graph, Icon.Layers, Icon.Shield, Icon.Cube, Icon.Plug];
  const items = (dict?.features?.items || []).map((it, i) => ({
    ...it,
    n: String(i + 1).padStart(2, "0"),
    Icon: iconOrder[i] || Icon.Sparkle,
  }));
  return (
    <section className="section-pad" id="features">
      <div className="container">
        <div className="section-head center">
          <div className="eyebrow"><span className="dot" /> {t("features.eyebrow")}</div>
          <THtml as="h2" path="features.titleHtml" />
          <p style={{ fontSize: 17 }}>{t("features.sub")}</p>
        </div>

        <div className="feat-grid">
          {items.map((it, i) => (
            <div className="feat" key={i}>
              <span className="f-num">/{it.n}</span>
              <div className="f-icon" style={{ color: i === 1 ? "var(--violet)" : i === 3 ? "var(--blue)" : "var(--mint)" }}>
                <it.Icon />
              </div>
              <h3>{it.title}</h3>
              <p>{it.body}</p>
              <div className="f-meta">
                {it.meta.map((m, j) => <span className="pill" key={j}>{m}</span>)}
              </div>
            </div>
          ))}
        </div>
      </div>
    </section>
  );
}

/* ---------- How it works ---------------------------------------------- */
function HowItWorks() {
  const { t, dict } = useI18n();
  const steps = dict?.how;
  if (!steps) return null;
  const renderStep = (n, key) => {
    const s = steps[key];
    return (
      <div className="step" key={key}>
        <span className="step-no"><span className="num">{n}</span> {s.label}</span>
        <h3>{s.title}</h3>
        <p>{s.body}</p>
        <div className="step-visual">
          {s.visual.map((line, i) => (
            <div key={i} className={`line ${line.cls}`} style={line.cls === "coral" ? { color: "var(--coral)" } : undefined}>{line.text}</div>
          ))}
        </div>
      </div>
    );
  };
  return (
    <section className="section-pad" id="how" style={{ background: "linear-gradient(180deg, transparent, rgba(122,242,200,0.025) 50%, transparent)" }}>
      <div className="container">
        <div className="section-head center">
          <div className="eyebrow"><span className="dot" /> {t("how.eyebrow")}</div>
          <THtml as="h2" path="how.titleHtml" />
        </div>

        <div className="steps">
          {renderStep(1, "step1")}
          {renderStep(2, "step2")}
          {renderStep(3, "step3")}
        </div>
      </div>
    </section>
  );
}

/* ---------- Use cases -------------------------------------------------- */
function UseCases() {
  const { t, dict } = useI18n();
  const items = (dict?.usecases?.items || []).map((u) => ({
    ...u,
    Icon: Icon[u.icon] || Icon.Book,
  }));
  return (
    <section className="section-pad" id="usecases">
      <div className="container">
        <div className="section-head">
          <div className="eyebrow"><span className="dot" /> {t("usecases.eyebrow")}</div>
          <THtml as="h2" path="usecases.titleHtml" />
        </div>
        <div className="uc-grid">
          {items.map((u, i) => (
            <div className="uc" key={i}>
              <div className="uc-icon"><u.Icon /></div>
              <div>
                <h3>{u.title}</h3>
                <p>{u.body}</p>
                <div className="uc-tags">
                  {u.tags.map((tag, j) => <span className="uc-tag" key={j}>{tag}</span>)}
                </div>
              </div>
            </div>
          ))}
        </div>
      </div>
    </section>
  );
}

/* ---------- Security --------------------------------------------------- */
function Security() {
  const { t, dict } = useI18n();
  const items = dict?.security?.items || [];
  return (
    <section className="section-pad" id="security">
      <div className="container">
        <div className="section-head center">
          <div className="eyebrow"><span className="dot" /> {t("security.eyebrow")}</div>
          <THtml as="h2" path="security.titleHtml" />
        </div>
        <div className="sec-grid">
          {items.map((s, i) => (
            <div className="sec" key={i}>
              <div className="sec-h"><Icon.Lock /> {s.h}</div>
              <h4>{s.t}</h4>
              <p>{s.b}</p>
            </div>
          ))}
        </div>
      </div>
    </section>
  );
}

/* ---------- FAQ -------------------------------------------------------- */
function FAQ() {
  const { t, dict } = useI18n();
  const items = dict?.faq?.items || [];
  const [open, setOpen] = useState(0);
  return (
    <section className="section-pad" id="faq">
      <div className="container">
        <div className="section-head">
          <div className="eyebrow"><span className="dot" /> {t("faq.eyebrow")}</div>
          <THtml as="h2" path="faq.titleHtml" />
        </div>
        <div className="faq">
          {items.map((it, i) => (
            <div className={`faq-item ${open === i ? "open" : ""}`} key={i}>
              <button
                type="button"
                className="faq-q"
                aria-expanded={open === i}
                aria-controls={`faq-a-${i}`}
                onClick={() => setOpen(open === i ? -1 : i)}
              >
                <span>{it.q}</span>
                <span className="plus" aria-hidden="true" />
              </button>
              <div className="faq-a" id={`faq-a-${i}`} role="region" aria-hidden={open !== i}>
                <div className="faq-a-inner">{it.a}</div>
              </div>
            </div>
          ))}
        </div>
      </div>
    </section>
  );
}

/* ---------- CTA + Footer ---------------------------------------------- */
function CTA() {
  const { t } = useI18n();
  return (
    <section className="section-pad-sm">
      <div className="container">
        <div className="cta-card">
          <div className="eyebrow" style={{ marginBottom: 18 }}>
            <span className="dot" /> {t("cta.eyebrow")}
          </div>
          <THtml as="h2" path="cta.titleHtml" />
          <p>{t("cta.sub")}</p>
          <div className="cta-buttons">
            <a className="btn btn-primary btn-lg" href="#demo">{t("cta.primary")} <Icon.Arrow /></a>
            <a className="btn btn-ghost btn-lg" href="#github"><Icon.Github /> {t("cta.secondary")}</a>
          </div>
        </div>
      </div>
    </section>
  );
}

function Footer() {
  const { t, dict } = useI18n();
  return (
    <footer className="footer">
      <div className="container">
        <div className="footer-row">
          <div className="footer-col">
            <a href="#" className="brand" style={{ marginBottom: 16 }}>
              <BrandMark /><span>AskMyDoc</span>
            </a>
            <p style={{ color: "var(--ink-3)", fontSize: 13.5, maxWidth: 280 }}>{t("footer.tagline")}</p>
            <div style={{ marginTop: 20 }}><LanguageSwitcher /></div>
          </div>
          <div className="footer-col">
            <h5>{t("footer.colProduct")}</h5>
            <a href="#features">{t("footer.product.features")}</a>
            <a href="#how">{t("footer.product.how")}</a>
            <a href="#security">{t("footer.product.security")}</a>
            <a href="#usecases">{t("footer.product.usecases")}</a>
          </div>
          <div className="footer-col">
            <h5>{t("footer.colResources")}</h5>
            <a href="#docs">{t("footer.resources.docs")}</a>
            <a href="#mcp">{t("footer.resources.mcp")}</a>
            <a href="#changelog">{t("footer.resources.changelog")}</a>
            <a href="#status">{t("footer.resources.status")}</a>
          </div>
          <div className="footer-col">
            <h5>{t("footer.colCompany")}</h5>
            <a href="#contact">{t("footer.company.contact")}</a>
            <a href="#blog">{t("footer.company.blog")}</a>
            <a href="#careers">{t("footer.company.careers")}</a>
            <a href="#sales">{t("footer.company.sales")}</a>
          </div>
          <div className="footer-col">
            <h5>{t("footer.colLegal")}</h5>
            <a href="#privacy">{t("footer.legal.privacy")}</a>
            <a href="#terms">{t("footer.legal.terms")}</a>
            <a href="#dpa">{t("footer.legal.dpa")}</a>
            <a href="#security-txt">{t("footer.legal.securitytxt")}</a>
          </div>
        </div>
        <div className="footer-bottom">
          <span>{t("footer.copyright")}</span>
          <span>{t("footer.version")}</span>
        </div>
      </div>
    </footer>
  );
}

/* ---------- App -------------------------------------------------------- */
function App() {
  return (
    <I18nProvider>
      <Nav />
      <Hero />
      <TrustStrip />
      <ProblemSolution />
      <Features />
      <HowItWorks />
      <UseCases />
      <Security />
      <FAQ />
      <CTA />
      <Footer />
    </I18nProvider>
  );
}

Object.assign(window, { App, Nav, Hero, TrustStrip, ProblemSolution, Features, HowItWorks, UseCases, Security, FAQ, CTA, Footer, LanguageSwitcher });

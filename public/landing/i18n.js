/* AskMyDoc landing — i18n dictionaries
 * Languages: it (default), en, fr, de, es
 *
 * Strings may contain inline HTML — rendered via dangerouslySetInnerHTML on
 * specific structured fields (titles, hero subline, body). Plain strings
 * are used for buttons, navigation, technical labels.
 */

const LANGS = [
  { code: "it", name: "Italiano", flag: "🇮🇹" },
  { code: "en", name: "English",  flag: "🇬🇧" },
  { code: "fr", name: "Français", flag: "🇫🇷" },
  { code: "de", name: "Deutsch",  flag: "🇩🇪" },
  { code: "es", name: "Español",  flag: "🇪🇸" },
];

const I18N = {
  /* ============================== ITALIAN (default) ===================== */
  it: {
    htmlLang: "it",
    metaDescription:
      "Knowledge base tipizzata, compilazione canonica, hybrid retrieval con rejected-approach injection. Multi-tenant, multi-provider, self-host. La grounding layer enterprise per LLM.",
    pageTitle: "AskMyDoc — Enterprise RAG che non ripete gli errori",

    nav: {
      product: "Prodotto",
      how: "Come funziona",
      security: "Sicurezza",
      usecases: "Use cases",
      faq: "FAQ",
      docs: "Docs",
      github: "GitHub",
      signin: "Accedi",
      bookdemo: "Prenota demo",
    },

    hero: {
      eyebrow: "v1.4 · canonical KB + MCP server",
      titleHtml:
        'Enterprise RAG<br/>che <span class="accent">non ripete</span> gli <span class="strike">errori</span>',
      sub:
        "Knowledge base tipizzata, compilazione canonica e iniezione degli approcci scartati. Multi-tenant, multi-provider, self-host. La grounding layer che doveva esserci dal giorno uno.",
      ctaPrimary: "Prova la demo",
      ctaSecondary: "Self-host gratis",
      metaProviders: "OpenAI · Anthropic · Gemini · OpenRouter · Regolo",
      metaDb: "Postgres + pgvector",
      metaStack: "Laravel 13 · React",
      graphProject: "project",
      graphTenant: "tenant",
      graphLive: "live",
    },

    graphPanel: {
      nodeTypesHeader: "tipi di nodo · 9",
      edgesHeader: "archi · 10 tipi",
      graphExpansionHeader: "graph expansion",
      row1hop: "vicini 1-hop",
      rowRejectedInj: "iniezione approcci scartati",
      rowCompositeFk: "FK composite tenant",
      floatingNodes: "nodi",
      floatingEdges: "archi",
      floatingRejected: "scartato",
      floatingExpansion: "espansione",
    },

    trustLabel: "Stack",

    problem: {
      eyebrow: "Il problema",
      titleHtml:
        'Il RAG vanilla allucina, ripete<br/><span class="accent">approcci già scartati</span>, non ha governance.',
      subHtml:
        'Embedding + top-k non sono una memoria. Sono un indice. Una memoria sa cosa è <span class="ink-1">canonico</span>, cosa è <span class="ink-1">scartato</span>, cosa <span class="ink-1">dipende</span> da cosa, e non ti consiglia di nuovo l\'errore che hai sistemato sei mesi fa.',
      badHead: "✕ Senza AskMyDoc",
      badTitle: "Stesso errore, ogni sei mesi.",
      badBody:
        "Un nuovo dev chiede al chatbot interno l'approccio per X. L'embedding pesca due README, un ticket vecchio e un commento abbandonato. Suggerisce di nuovo la stessa soluzione che avete già bocciato dopo un incidente in prod.",
      goodHead: "✓ Con AskMyDoc",
      goodTitle: "Il grafo conosce ciò che è stato scartato.",
      goodBodyHtml:
        'La query attiva un\'espansione 1-hop sul nodo <code class="mono ink-1">module:auth</code>. Trova un nodo <code class="mono coral">rejected-approach</code> collegato via <code class="mono ink-1"> supersedes</code>. L\'LLM riceve l\'antipattern in contesto e la risposta inizia con: "Non usare questo approccio, è stato già tentato — vedi INC-2025-04."',
    },

    retrieval: {
      query: "Come gestiamo il refresh token nel tenant acme-prod?",
      headPath: "POST /api/kb/query",
      headFusion: "hybrid (0.6·v + 0.3·k + 0.1·h)",
      live: "live",
      pipeVector: "vector · pgvector",
      pipeKeyword: "keyword · FTS",
      pipeRerank: "rerank · head",
      answerHead: "↳ risposta grounded",
      answerMetaWaiting: "in attesa…",
      answerMetaDone: "847ms · 3 citazioni · 1 scartato",
      answerBodyHtml:
        'Per <span class="mono ink-1">acme-prod</span> il refresh segue la rotazione definita in <span class="cite">ADR-0011</span>: token di durata 7gg, single-use, revocato via <span class="mono ink-1">tenant_id</span> scope. Vedi runbook <span class="cite">auth/refresh-flow</span> e l\'incidente collegato <span class="cite">INC-2025-04</span>.',
      rejectedHtml:
        '<b>Non proporre</b> long-lived JWT senza rotation — approccio scartato (supersedes m:auth)',
    },

    features: {
      eyebrow: "Prodotto",
      titleHtml: 'Sei capacità che <span class="accent">il RAG vanilla</span> non ha.',
      sub: "Una piattaforma sola, opinionata sulle parti che contano, agnostica sulle parti che cambiano.",
      items: [
        {
          title: "Hybrid retrieval con reranking",
          body: "Fusione 0.6·vector + 0.3·keyword + 0.1·head. pgvector accanto a Postgres FTS, niente servizi esterni. Embedding cache con LRU pruning integrato.",
          meta: ["pgvector", "FTS", "rerank"],
        },
        {
          title: "Canonical knowledge graph",
          body: "9 tipi di nodo, 10 tipi di edge. Frontmatter YAML + wikilink, espansione 1-hop a query time, iniezione automatica degli approcci scartati.",
          meta: ["typed nodes", "rejected-approach"],
        },
        {
          title: "Multi-provider, zero lock-in",
          body: "OpenAI, Anthropic, Gemini, OpenRouter, Regolo — orchestrati con raw Http::, nessun SDK black-box. Chat e embedding provider configurabili separatamente.",
          meta: ["5 provider", "no SDK"],
        },
        {
          title: "Multi-tenant strutturale",
          body: "tenant_id su 17+ tabelle, composite uniques per slug/doc_id, FK composite che rendono i cross-tenant edges impossibili a livello di schema.",
          meta: ["isolation by schema"],
        },
        {
          title: "Admin SPA completa",
          body: "KPI dashboard, RBAC Spatie, KB tree explorer + inline editor, graph viewer, PDF export, log viewer a 5 tab e Artisan runner whitelisted.",
          meta: ["React + TanStack"],
        },
        {
          title: "MCP server pronto",
          body: "Server enterprise-kb con 10 tool (5 retrieval + 5 canonical/promote). Si collega a Claude Desktop, Cursor o qualsiasi client MCP-compatibile.",
          meta: ["10 tool", "Laravel MCP 0.7"],
        },
      ],
    },

    how: {
      eyebrow: "Come funziona",
      titleHtml: 'Tre passi. <span class="accent">Niente magia.</span>',
      step1: {
        label: "Ingest",
        title: "Git push → auto-ingest.",
        body: "Un'azione GitHub esegue l'upsert idempotente su (project_key, source_path, version_hash). Il chunker fence-aware segue le sezioni del markdown.",
        visual: [
          { cls: "dim", text: "› git push origin main" },
          { cls: "info", text: "→ GH Action: ingest-to-askmydocs" },
          { cls: "dim", text: "  sha256: e7d2…91a" },
          { cls: "", text: "  POST /api/kb/ingest" },
          { cls: "ok", text: "✓ 14 chunk upserted" },
          { cls: "ok", text: "✓ idempotente (no dup)" },
          { cls: "dim", text: "  job: IngestDocumentJob" },
        ],
      },
      step2: {
        label: "Compile",
        title: "Promozione human-gated.",
        body: "Un LLM suggerisce candidati canonical (no-write). Un umano valida. Solo a commit i nodi entrano nel grafo con audit trail immutabile.",
        visual: [
          { cls: "", text: "/promotion/suggest" },
          { cls: "dim", text: "  ↳ 3 candidati" },
          { cls: "dim", text: "    · decision: ADR-0011" },
          { cls: "dim", text: "    · rejected: long-lived JWT" },
          { cls: "dim", text: "    · runbook: refresh-flow" },
          { cls: "info", text: "  revisione di alice@acme" },
          { cls: "ok", text: "✓ promote → canonical" },
          { cls: "dim", text: "  audit: kb_canonical_audit" },
        ],
      },
      step3: {
        label: "Answer",
        title: "Grounded. Citata. Auditata.",
        body: "Hybrid retrieval + espansione 1-hop sul grafo. Gli approcci scartati entrano in contesto. Refusal path se non c'è grounding sufficiente.",
        visual: [
          { cls: "dim", text: "› query \"refresh token acme\"" },
          { cls: "info", text: "  hybrid: 9 hit (v+k+h)" },
          { cls: "info", text: "  graph: +2 vicini (1-hop)" },
          { cls: "coral", text: "  ⚠ 1 approccio scartato iniettato" },
          { cls: "", text: "  llm: anthropic / sonnet" },
          { cls: "ok", text: "✓ risposta · 3 citazioni" },
          { cls: "dim", text: "  audit loggato · 847ms" },
        ],
      },
    },

    usecases: {
      eyebrow: "Use cases",
      titleHtml: 'Dove <span class="accent">funziona davvero</span>.',
      items: [
        {
          icon: "Book",
          title: "Engineering docs & ADR repository",
          body: "Trasforma README, ADR e RFC in un grafo canonico tipizzato. Le decisioni superate vengono linkate via supersedes, non perse.",
          tags: ["ADR", "RFC", "Runbook"],
        },
        {
          icon: "Support",
          title: "Customer support knowledge base",
          body: "Risposte grounded con citazioni, refusal path quando manca grounding, filter preset per categoria prodotto e versione.",
          tags: ["Citazioni", "Refusal", "Filtri"],
        },
        {
          icon: "Scale",
          title: "Compliance & Patent Box audit",
          body: "Audit trail immutabile su ogni promotion canonica. Soft delete + retention configurabile. Export PDF certificabile.",
          tags: ["Audit", "Retention", "PDF"],
        },
        {
          icon: "Brain",
          title: "LLM grounding multi-team",
          body: "MCP server con 10 tool. Claude Desktop e Cursor parlano direttamente al tuo grafo canonico, con isolamento tenant.",
          tags: ["MCP", "Claude", "Cursor"],
        },
      ],
    },

    security: {
      eyebrow: "Security & compliance",
      titleHtml: 'L\'isolamento <span class="accent">è strutturale</span>, non promesso.',
      items: [
        { h: "Isolation", t: "Tenant by schema", b: "tenant_id su 17+ tabelle, FK composite. Cross-tenant edges impossibili strutturalmente, non per check applicativo." },
        { h: "Audit", t: "Trail immutabile", b: "kb_canonical_audit registra ogni promotion. Comandi distruttivi richiedono token monouso DB-backed." },
        { h: "Retention", t: "Soft delete + sweep", b: "Retention configurabile (default 30gg). Scheduler notturno per prune embedding, chat-log e deleted record." },
        { h: "RBAC", t: "Spatie + Sanctum", b: "Ruoli e permessi granulari. Sanctum per API auth, RBAC su ogni endpoint admin. Nessun bypass." },
        { h: "Providers", t: "No SDK black box", b: "Raw Http:: verso ogni provider. Niente dipendenze opache, niente telemetria nascosta, niente data leak indiretti." },
      ],
    },

    faq: {
      eyebrow: "FAQ",
      titleHtml: 'Domande <span class="accent">ricorrenti</span>.',
      items: [
        { q: "Quali LLM e provider supportate?",
          a: "OpenAI, Anthropic, Gemini, OpenRouter e Regolo per la chat. Chat provider ed embedding provider sono configurabili separatamente — niente SDK, solo raw Http::." },
        { q: "Posso cambiare provider di embedding senza re-index?",
          a: "No, e nessuno può: la dimensione dell'embedding cambia tra provider (es. 1536 vs 768 vs 3072). Quando cambi provider il re-index è inevitabile. Lo gestiamo con job batch idempotenti — vedi README \"Embedding dimension gotcha\"." },
        { q: "Funziona on-prem o air-gapped?",
          a: "Sì. AskMyDoc è self-host first: Laravel 13 + PostgreSQL + pgvector girano ovunque. Per air-gapped basta puntare la chat verso un provider on-prem (es. vLLM, Ollama via OpenAI-compatible)." },
        { q: "Supporto multi-lingua?",
          a: "Sì. Auto-translation integrata (Laravel skill), filter bar per lingua nelle conversazioni, citazioni preservate nella lingua del documento sorgente." },
        { q: "Come funziona l'integrazione MCP con Claude Desktop o Cursor?",
          a: "Includiamo un server enterprise-kb (Laravel\\Mcp ^0.7) con 10 tool: 5 di retrieval (hybrid search, graph expansion, ecc.) e 5 di canonical/promote. Lo configuri come MCP server nel client e ottieni accesso al grafo tenant-scoped." },
        { q: "Quanto è invasivo l'onboarding di una codebase esistente?",
          a: "Minimo: una GitHub Action su un repo di docs (anche Markdown puro). L'ingestion è idempotente su SHA-256, quindi push successivi non duplicano. La promozione canonica resta human-gated, partite zero e crescete." },
      ],
    },

    cta: {
      eyebrow: "Pronto a partire",
      titleHtml:
        'La grounding layer<br/>che <span style="font-family:Instrument Serif, serif;font-style:italic;color:var(--mint)">doveva esserci</span> dal day one.',
      sub: "Self-host gratis in 10 minuti, o lascia che la gestiamo noi. Multi-tenant, audit-ready, MCP-compatibile.",
      primary: "Prenota una demo",
      secondary: "Star su GitHub",
    },

    footer: {
      tagline: "Enterprise RAG con knowledge graph canonico. Self-host first, MCP-native, multi-tenant by schema.",
      colProduct: "Prodotto",
      colResources: "Risorse",
      colCompany: "Azienda",
      colLegal: "Legale",
      product: { features: "Features", how: "Come funziona", security: "Sicurezza", usecases: "Use cases" },
      resources: { docs: "Docs", mcp: "Setup MCP", changelog: "Changelog", status: "Status" },
      company: { contact: "Contatti", blog: "Blog", careers: "Lavora con noi", sales: "Vendite" },
      legal: { privacy: "Privacy", terms: "Termini", dpa: "DPA", securitytxt: "security.txt" },
      copyright: "© 2026 AskMyDoc · Costruito con Laravel 13, PostgreSQL, pgvector e opinioni testarde.",
      version: "v1.4.2 · MIT (core) · Licenza commerciale disponibile",
    },

    langSwitch: "Lingua",
  },

  /* ============================== ENGLISH =============================== */
  en: {
    htmlLang: "en",
    metaDescription:
      "Typed knowledge base, canonical compilation, hybrid retrieval with rejected-approach injection. Multi-tenant, multi-provider, self-host. The enterprise grounding layer for LLMs.",
    pageTitle: "AskMyDoc — Enterprise RAG that doesn't repeat past mistakes",

    nav: {
      product: "Product",
      how: "How it works",
      security: "Security",
      usecases: "Use cases",
      faq: "FAQ",
      docs: "Docs",
      github: "GitHub",
      signin: "Sign in",
      bookdemo: "Book demo",
    },

    hero: {
      eyebrow: "v1.4 · canonical KB + MCP server",
      titleHtml:
        'Enterprise RAG<br/>that <span class="accent">won\'t repeat</span> past <span class="strike">mistakes</span>',
      sub:
        "Typed knowledge base, canonical compilation, rejected-approach injection. Multi-tenant, multi-provider, self-host. The grounding layer that should have been there since day one.",
      ctaPrimary: "Try the demo",
      ctaSecondary: "Self-host free",
      metaProviders: "OpenAI · Anthropic · Gemini · OpenRouter · Regolo",
      metaDb: "Postgres + pgvector",
      metaStack: "Laravel 13 · React",
      graphProject: "project",
      graphTenant: "tenant",
      graphLive: "live",
    },

    graphPanel: {
      nodeTypesHeader: "node types · 9",
      edgesHeader: "edges · 10 types",
      graphExpansionHeader: "graph expansion",
      row1hop: "1-hop neighbors",
      rowRejectedInj: "rejected-approach inj.",
      rowCompositeFk: "composite tenant FK",
      floatingNodes: "nodes",
      floatingEdges: "edges",
      floatingRejected: "rejected",
      floatingExpansion: "expansion",
    },

    trustLabel: "Stack",

    problem: {
      eyebrow: "The problem",
      titleHtml:
        'Vanilla RAG hallucinates, repeats<br/><span class="accent">already-rejected approaches</span>, has no governance.',
      subHtml:
        'Embedding + top-k isn\'t memory. It\'s an index. A memory knows what\'s <span class="ink-1">canonical</span>, what was <span class="ink-1">rejected</span>, what <span class="ink-1">depends</span> on what — and won\'t suggest again the mistake you fixed six months ago.',
      badHead: "✕ Without AskMyDoc",
      badTitle: "Same mistake, every six months.",
      badBody:
        "A new dev asks the internal chatbot for the approach to X. The embedding picks up two READMEs, an old ticket and an abandoned comment. It suggests the same solution you already rejected after a prod incident.",
      goodHead: "✓ With AskMyDoc",
      goodTitle: "The graph knows what was discarded.",
      goodBodyHtml:
        'The query triggers a 1-hop expansion on the <code class="mono ink-1">module:auth</code> node. It finds a <code class="mono coral">rejected-approach</code> node linked via <code class="mono ink-1"> supersedes</code>. The LLM gets the antipattern in context and the answer starts with: "Don\'t use this approach — it was already tried, see INC-2025-04."',
    },

    retrieval: {
      query: "How do we handle refresh tokens in the acme-prod tenant?",
      headPath: "POST /api/kb/query",
      headFusion: "hybrid (0.6·v + 0.3·k + 0.1·h)",
      live: "live",
      pipeVector: "vector · pgvector",
      pipeKeyword: "keyword · FTS",
      pipeRerank: "rerank · head",
      answerHead: "↳ grounded answer",
      answerMetaWaiting: "waiting…",
      answerMetaDone: "847ms · 3 citations · 1 rejected",
      answerBodyHtml:
        'For <span class="mono ink-1">acme-prod</span>, refresh follows the rotation defined in <span class="cite">ADR-0011</span>: 7-day token, single-use, revoked via <span class="mono ink-1">tenant_id</span> scope. See runbook <span class="cite">auth/refresh-flow</span> and related incident <span class="cite">INC-2025-04</span>.',
      rejectedHtml:
        '<b>Do not propose</b> long-lived JWT without rotation — rejected approach (supersedes m:auth)',
    },

    features: {
      eyebrow: "Product",
      titleHtml: 'Six capabilities <span class="accent">vanilla RAG</span> doesn\'t have.',
      sub: "One platform, opinionated about what matters, agnostic about what changes.",
      items: [
        {
          title: "Hybrid retrieval with reranking",
          body: "Fusion 0.6·vector + 0.3·keyword + 0.1·head. pgvector alongside Postgres FTS, no external services. Embedding cache with built-in LRU pruning.",
          meta: ["pgvector", "FTS", "rerank"],
        },
        {
          title: "Canonical knowledge graph",
          body: "9 node types, 10 edge types. YAML frontmatter + wikilinks, 1-hop expansion at query time, automatic injection of rejected approaches.",
          meta: ["typed nodes", "rejected-approach"],
        },
        {
          title: "Multi-provider, zero lock-in",
          body: "OpenAI, Anthropic, Gemini, OpenRouter, Regolo — orchestrated with raw Http::, no SDK black-box. Chat and embedding providers configurable separately.",
          meta: ["5 providers", "no SDK"],
        },
        {
          title: "Structural multi-tenant",
          body: "tenant_id on 17+ tables, composite uniques for slug/doc_id, composite FKs making cross-tenant edges schema-level impossible.",
          meta: ["isolation by schema"],
        },
        {
          title: "Full admin SPA",
          body: "KPI dashboard, Spatie RBAC, KB tree explorer + inline editor, graph viewer, PDF export, 5-tab log viewer and whitelisted Artisan runner.",
          meta: ["React + TanStack"],
        },
        {
          title: "MCP server ready",
          body: "enterprise-kb server with 10 tools (5 retrieval + 5 canonical/promote). Plugs into Claude Desktop, Cursor or any MCP-compatible client.",
          meta: ["10 tools", "Laravel MCP 0.7"],
        },
      ],
    },

    how: {
      eyebrow: "How it works",
      titleHtml: 'Three steps. <span class="accent">No magic.</span>',
      step1: {
        label: "Ingest",
        title: "Git push → auto-ingest.",
        body: "A GitHub Action runs an idempotent upsert on (project_key, source_path, version_hash). The fence-aware chunker follows the markdown sections.",
        visual: [
          { cls: "dim", text: "› git push origin main" },
          { cls: "info", text: "→ GH Action: ingest-to-askmydocs" },
          { cls: "dim", text: "  sha256: e7d2…91a" },
          { cls: "", text: "  POST /api/kb/ingest" },
          { cls: "ok", text: "✓ 14 chunks upserted" },
          { cls: "ok", text: "✓ idempotent (no dup)" },
          { cls: "dim", text: "  job: IngestDocumentJob" },
        ],
      },
      step2: {
        label: "Compile",
        title: "Human-gated promotion.",
        body: "An LLM suggests canonical candidates (no-write). A human validates. Only on commit do nodes enter the graph with immutable audit trail.",
        visual: [
          { cls: "", text: "/promotion/suggest" },
          { cls: "dim", text: "  ↳ 3 candidates" },
          { cls: "dim", text: "    · decision: ADR-0011" },
          { cls: "dim", text: "    · rejected: long-lived JWT" },
          { cls: "dim", text: "    · runbook: refresh-flow" },
          { cls: "info", text: "  review by alice@acme" },
          { cls: "ok", text: "✓ promote → canonical" },
          { cls: "dim", text: "  audit: kb_canonical_audit" },
        ],
      },
      step3: {
        label: "Answer",
        title: "Grounded. Cited. Audited.",
        body: "Hybrid retrieval + 1-hop graph expansion. Rejected approaches enter the context. Refusal path when grounding isn't sufficient.",
        visual: [
          { cls: "dim", text: "› query \"refresh token acme\"" },
          { cls: "info", text: "  hybrid: 9 hits (v+k+h)" },
          { cls: "info", text: "  graph: +2 neighbors (1-hop)" },
          { cls: "coral", text: "  ⚠ 1 rejected-approach injected" },
          { cls: "", text: "  llm: anthropic / sonnet" },
          { cls: "ok", text: "✓ answer · 3 citations" },
          { cls: "dim", text: "  audit logged · 847ms" },
        ],
      },
    },

    usecases: {
      eyebrow: "Use cases",
      titleHtml: 'Where it <span class="accent">actually works</span>.',
      items: [
        {
          icon: "Book",
          title: "Engineering docs & ADR repository",
          body: "Turn READMEs, ADRs and RFCs into a typed canonical graph. Superseded decisions get linked via supersedes — not lost.",
          tags: ["ADR", "RFC", "Runbook"],
        },
        {
          icon: "Support",
          title: "Customer support knowledge base",
          body: "Grounded answers with citations, refusal path when grounding is missing, filter presets per product category and version.",
          tags: ["Citations", "Refusal", "Filters"],
        },
        {
          icon: "Scale",
          title: "Compliance & Patent Box audit",
          body: "Immutable audit trail on every canonical promotion. Soft delete + configurable retention. Certifiable PDF export.",
          tags: ["Audit", "Retention", "PDF"],
        },
        {
          icon: "Brain",
          title: "Multi-team LLM grounding",
          body: "MCP server with 10 tools. Claude Desktop and Cursor talk directly to your canonical graph, with tenant isolation.",
          tags: ["MCP", "Claude", "Cursor"],
        },
      ],
    },

    security: {
      eyebrow: "Security & compliance",
      titleHtml: 'Isolation <span class="accent">is structural</span>, not promised.',
      items: [
        { h: "Isolation", t: "Tenant by schema", b: "tenant_id on 17+ tables, composite FKs. Cross-tenant edges impossible structurally — not by application check." },
        { h: "Audit", t: "Immutable trail", b: "kb_canonical_audit records every promotion. Destructive commands require a DB-backed single-use token." },
        { h: "Retention", t: "Soft delete + sweep", b: "Configurable retention (default 30d). Nightly scheduler prunes embeddings, chat-logs and deleted records." },
        { h: "RBAC", t: "Spatie + Sanctum", b: "Granular roles and permissions. Sanctum for API auth, RBAC on every admin endpoint. No bypass." },
        { h: "Providers", t: "No SDK black box", b: "Raw Http:: to every provider. No opaque dependencies, no hidden telemetry, no indirect data leaks." },
      ],
    },

    faq: {
      eyebrow: "FAQ",
      titleHtml: 'Recurring <span class="accent">questions</span>.',
      items: [
        { q: "Which LLMs and providers do you support?",
          a: "OpenAI, Anthropic, Gemini, OpenRouter and Regolo for chat. Chat provider and embedding provider are configurable separately — no SDK, just raw Http::." },
        { q: "Can I switch embedding provider without re-indexing?",
          a: "No, and nobody can: embedding dimensions change between providers (e.g. 1536 vs 768 vs 3072). When you switch, re-indexing is unavoidable. We handle it with idempotent batch jobs — see the README \"Embedding dimension gotcha\"." },
        { q: "Does it work on-prem or air-gapped?",
          a: "Yes. AskMyDoc is self-host first: Laravel 13 + PostgreSQL + pgvector run anywhere. For air-gapped, point chat at an on-prem provider (e.g. vLLM, Ollama via OpenAI-compatible)." },
        { q: "Multi-language support?",
          a: "Yes. Built-in auto-translation (Laravel skill), language filter bar in conversations, citations preserved in the source document's language." },
        { q: "How does MCP integration with Claude Desktop or Cursor work?",
          a: "We ship an enterprise-kb server (Laravel\\Mcp ^0.7) with 10 tools: 5 retrieval (hybrid search, graph expansion, etc.) and 5 canonical/promote. Configure it as an MCP server in the client and get tenant-scoped graph access." },
        { q: "How invasive is onboarding an existing codebase?",
          a: "Minimal: a GitHub Action on a docs repo (plain Markdown works). Ingestion is SHA-256 idempotent, so subsequent pushes don't duplicate. Canonical promotion stays human-gated — start at zero and grow." },
      ],
    },

    cta: {
      eyebrow: "Ready to start",
      titleHtml:
        'The grounding layer<br/>that <span style="font-family:Instrument Serif, serif;font-style:italic;color:var(--mint)">should have been there</span> from day one.',
      sub: "Self-host free in 10 minutes, or let us run it for you. Multi-tenant, audit-ready, MCP-compatible.",
      primary: "Book a demo",
      secondary: "Star on GitHub",
    },

    footer: {
      tagline: "Enterprise RAG with canonical knowledge graph. Self-host first, MCP-native, multi-tenant by schema.",
      colProduct: "Product",
      colResources: "Resources",
      colCompany: "Company",
      colLegal: "Legal",
      product: { features: "Features", how: "How it works", security: "Security", usecases: "Use cases" },
      resources: { docs: "Docs", mcp: "MCP setup", changelog: "Changelog", status: "Status" },
      company: { contact: "Contact", blog: "Blog", careers: "Careers", sales: "Sales" },
      legal: { privacy: "Privacy", terms: "Terms", dpa: "DPA", securitytxt: "security.txt" },
      copyright: "© 2026 AskMyDoc · Built with Laravel 13, PostgreSQL, pgvector and stubborn opinions.",
      version: "v1.4.2 · MIT (core) · Commercial license available",
    },

    langSwitch: "Language",
  },

  /* ============================== FRENCH ================================ */
  fr: {
    htmlLang: "fr",
    metaDescription:
      "Base de connaissances typée, compilation canonique, recherche hybride avec injection d'approches rejetées. Multi-tenant, multi-provider, auto-hébergé. La couche de grounding enterprise pour LLM.",
    pageTitle: "AskMyDoc — RAG enterprise qui ne répète pas les erreurs",

    nav: {
      product: "Produit",
      how: "Fonctionnement",
      security: "Sécurité",
      usecases: "Cas d'usage",
      faq: "FAQ",
      docs: "Docs",
      github: "GitHub",
      signin: "Connexion",
      bookdemo: "Demander une démo",
    },

    hero: {
      eyebrow: "v1.4 · KB canonique + serveur MCP",
      titleHtml:
        'RAG enterprise<br/>qui <span class="accent">ne répète pas</span> les <span class="strike">erreurs</span>',
      sub:
        "Base de connaissances typée, compilation canonique, injection des approches rejetées. Multi-tenant, multi-provider, auto-hébergé. La couche de grounding qui aurait dû exister dès le premier jour.",
      ctaPrimary: "Essayer la démo",
      ctaSecondary: "Auto-héberger gratuitement",
      metaProviders: "OpenAI · Anthropic · Gemini · OpenRouter · Regolo",
      metaDb: "Postgres + pgvector",
      metaStack: "Laravel 13 · React",
      graphProject: "projet",
      graphTenant: "tenant",
      graphLive: "live",
    },

    graphPanel: {
      nodeTypesHeader: "types de nœuds · 9",
      edgesHeader: "arêtes · 10 types",
      graphExpansionHeader: "expansion du graphe",
      row1hop: "voisins 1-hop",
      rowRejectedInj: "inj. approche rejetée",
      rowCompositeFk: "FK composite tenant",
      floatingNodes: "nœuds",
      floatingEdges: "arêtes",
      floatingRejected: "rejeté",
      floatingExpansion: "expansion",
    },

    trustLabel: "Stack",

    problem: {
      eyebrow: "Le problème",
      titleHtml:
        'Le RAG vanille hallucine, répète<br/><span class="accent">des approches déjà rejetées</span>, sans gouvernance.',
      subHtml:
        'Embedding + top-k, ce n\'est pas une mémoire. C\'est un index. Une mémoire sait ce qui est <span class="ink-1">canonique</span>, ce qui a été <span class="ink-1">rejeté</span>, ce qui <span class="ink-1">dépend</span> de quoi — et ne suggère pas à nouveau l\'erreur réglée il y a six mois.',
      badHead: "✕ Sans AskMyDoc",
      badTitle: "La même erreur, tous les six mois.",
      badBody:
        "Un nouveau dev demande au chatbot interne l'approche pour X. L'embedding remonte deux README, un vieux ticket et un commentaire abandonné. Il suggère à nouveau la solution déjà rejetée après un incident en prod.",
      goodHead: "✓ Avec AskMyDoc",
      goodTitle: "Le graphe connaît ce qui a été écarté.",
      goodBodyHtml:
        'La requête déclenche une expansion 1-hop sur le nœud <code class="mono ink-1">module:auth</code>. Elle trouve un nœud <code class="mono coral">rejected-approach</code> relié via <code class="mono ink-1"> supersedes</code>. Le LLM reçoit l\'antipattern en contexte et la réponse commence par : « N\'utilisez pas cette approche, elle a déjà été tentée — voir INC-2025-04. »',
    },

    retrieval: {
      query: "Comment gérons-nous le refresh token dans le tenant acme-prod ?",
      headPath: "POST /api/kb/query",
      headFusion: "hybride (0,6·v + 0,3·k + 0,1·h)",
      live: "live",
      pipeVector: "vector · pgvector",
      pipeKeyword: "keyword · FTS",
      pipeRerank: "rerank · head",
      answerHead: "↳ réponse grounded",
      answerMetaWaiting: "en attente…",
      answerMetaDone: "847ms · 3 citations · 1 rejeté",
      answerBodyHtml:
        'Pour <span class="mono ink-1">acme-prod</span>, le refresh suit la rotation définie dans <span class="cite">ADR-0011</span> : token 7j, à usage unique, révoqué via <span class="mono ink-1">tenant_id</span> scope. Voir le runbook <span class="cite">auth/refresh-flow</span> et l\'incident lié <span class="cite">INC-2025-04</span>.',
      rejectedHtml:
        '<b>Ne pas proposer</b> de JWT longue durée sans rotation — approche rejetée (supersedes m:auth)',
    },

    features: {
      eyebrow: "Produit",
      titleHtml: 'Six capacités que <span class="accent">le RAG vanille</span> n\'a pas.',
      sub: "Une seule plateforme, opinionée sur ce qui compte, agnostique sur ce qui change.",
      items: [
        {
          title: "Hybrid retrieval avec reranking",
          body: "Fusion 0,6·vector + 0,3·keyword + 0,1·head. pgvector à côté de Postgres FTS, sans services externes. Cache d'embedding avec LRU pruning intégré.",
          meta: ["pgvector", "FTS", "rerank"],
        },
        {
          title: "Knowledge graph canonique",
          body: "9 types de nœuds, 10 types d'arêtes. Frontmatter YAML + wikilinks, expansion 1-hop à la requête, injection automatique des approches rejetées.",
          meta: ["nœuds typés", "rejected-approach"],
        },
        {
          title: "Multi-provider, zéro lock-in",
          body: "OpenAI, Anthropic, Gemini, OpenRouter, Regolo — orchestrés via raw Http::, sans SDK black-box. Chat et embedding configurables séparément.",
          meta: ["5 providers", "no SDK"],
        },
        {
          title: "Multi-tenant structurel",
          body: "tenant_id sur 17+ tables, uniques composites pour slug/doc_id, FK composites rendant impossibles les arêtes cross-tenant au niveau schéma.",
          meta: ["isolation par schéma"],
        },
        {
          title: "SPA admin complète",
          body: "Dashboard KPI, RBAC Spatie, explorateur KB + éditeur inline, visualiseur de graphe, export PDF, log viewer 5 onglets et runner Artisan whitelisté.",
          meta: ["React + TanStack"],
        },
        {
          title: "Serveur MCP prêt",
          body: "Serveur enterprise-kb avec 10 outils (5 retrieval + 5 canonical/promote). Se branche sur Claude Desktop, Cursor ou tout client MCP-compatible.",
          meta: ["10 outils", "Laravel MCP 0.7"],
        },
      ],
    },

    how: {
      eyebrow: "Fonctionnement",
      titleHtml: 'Trois étapes. <span class="accent">Aucune magie.</span>',
      step1: {
        label: "Ingest",
        title: "Git push → auto-ingest.",
        body: "Une GitHub Action exécute un upsert idempotent sur (project_key, source_path, version_hash). Le chunker fence-aware suit les sections du markdown.",
        visual: [
          { cls: "dim", text: "› git push origin main" },
          { cls: "info", text: "→ GH Action: ingest-to-askmydocs" },
          { cls: "dim", text: "  sha256: e7d2…91a" },
          { cls: "", text: "  POST /api/kb/ingest" },
          { cls: "ok", text: "✓ 14 chunks upserted" },
          { cls: "ok", text: "✓ idempotent (no dup)" },
          { cls: "dim", text: "  job: IngestDocumentJob" },
        ],
      },
      step2: {
        label: "Compile",
        title: "Promotion human-gated.",
        body: "Un LLM suggère des candidats canoniques (no-write). Un humain valide. Seul le commit fait entrer les nœuds dans le graphe avec audit trail immuable.",
        visual: [
          { cls: "", text: "/promotion/suggest" },
          { cls: "dim", text: "  ↳ 3 candidats" },
          { cls: "dim", text: "    · decision: ADR-0011" },
          { cls: "dim", text: "    · rejected: long-lived JWT" },
          { cls: "dim", text: "    · runbook: refresh-flow" },
          { cls: "info", text: "  revue par alice@acme" },
          { cls: "ok", text: "✓ promote → canonical" },
          { cls: "dim", text: "  audit: kb_canonical_audit" },
        ],
      },
      step3: {
        label: "Answer",
        title: "Grounded. Citée. Auditée.",
        body: "Hybrid retrieval + expansion 1-hop du graphe. Les approches rejetées entrent dans le contexte. Refusal path si le grounding ne suffit pas.",
        visual: [
          { cls: "dim", text: "› query \"refresh token acme\"" },
          { cls: "info", text: "  hybrid: 9 hits (v+k+h)" },
          { cls: "info", text: "  graph: +2 voisins (1-hop)" },
          { cls: "coral", text: "  ⚠ 1 approche rejetée injectée" },
          { cls: "", text: "  llm: anthropic / sonnet" },
          { cls: "ok", text: "✓ réponse · 3 citations" },
          { cls: "dim", text: "  audit loggé · 847ms" },
        ],
      },
    },

    usecases: {
      eyebrow: "Cas d'usage",
      titleHtml: 'Où ça <span class="accent">marche vraiment</span>.',
      items: [
        {
          icon: "Book",
          title: "Docs ingénierie & dépôt ADR",
          body: "Transformez README, ADR et RFC en un graphe canonique typé. Les décisions remplacées sont liées via supersedes, pas perdues.",
          tags: ["ADR", "RFC", "Runbook"],
        },
        {
          icon: "Support",
          title: "Base de connaissances support client",
          body: "Réponses grounded avec citations, refusal path en l'absence de grounding, presets de filtre par catégorie et version produit.",
          tags: ["Citations", "Refusal", "Filtres"],
        },
        {
          icon: "Scale",
          title: "Compliance & audit Patent Box",
          body: "Audit trail immuable sur chaque promotion canonique. Soft delete + retention configurable. Export PDF certifiable.",
          tags: ["Audit", "Retention", "PDF"],
        },
        {
          icon: "Brain",
          title: "Grounding LLM multi-équipes",
          body: "Serveur MCP avec 10 outils. Claude Desktop et Cursor parlent directement à votre graphe canonique, avec isolation tenant.",
          tags: ["MCP", "Claude", "Cursor"],
        },
      ],
    },

    security: {
      eyebrow: "Sécurité & conformité",
      titleHtml: 'L\'isolation <span class="accent">est structurelle</span>, pas promise.',
      items: [
        { h: "Isolation", t: "Tenant par schéma", b: "tenant_id sur 17+ tables, FK composites. Arêtes cross-tenant impossibles structurellement, pas par contrôle applicatif." },
        { h: "Audit", t: "Trail immuable", b: "kb_canonical_audit enregistre chaque promotion. Les commandes destructrices exigent un token usage unique en base." },
        { h: "Retention", t: "Soft delete + sweep", b: "Retention configurable (30j par défaut). Scheduler nocturne pour purger embeddings, chat-logs et records supprimés." },
        { h: "RBAC", t: "Spatie + Sanctum", b: "Rôles et permissions granulaires. Sanctum pour l'API, RBAC sur chaque endpoint admin. Aucun bypass." },
        { h: "Providers", t: "Pas de SDK black box", b: "Raw Http:: vers chaque provider. Aucune dépendance opaque, aucune télémétrie cachée, aucune fuite de données indirecte." },
      ],
    },

    faq: {
      eyebrow: "FAQ",
      titleHtml: 'Questions <span class="accent">récurrentes</span>.',
      items: [
        { q: "Quels LLM et providers supportez-vous ?",
          a: "OpenAI, Anthropic, Gemini, OpenRouter et Regolo pour le chat. Chat provider et embedding provider sont configurables séparément — pas de SDK, juste raw Http::." },
        { q: "Puis-je changer de provider d'embedding sans réindexer ?",
          a: "Non, et personne ne le peut : la dimension d'embedding change entre providers (par ex. 1536 vs 768 vs 3072). Quand vous changez, la réindexation est inévitable. Nous le gérons avec des jobs batch idempotents — voir README « Embedding dimension gotcha »." },
        { q: "Fonctionne-t-il on-prem ou air-gapped ?",
          a: "Oui. AskMyDoc est self-host first : Laravel 13 + PostgreSQL + pgvector tournent partout. Pour l'air-gapped, pointez le chat vers un provider on-prem (par ex. vLLM, Ollama via OpenAI-compatible)." },
        { q: "Support multilingue ?",
          a: "Oui. Auto-traduction intégrée (skill Laravel), barre de filtre par langue dans les conversations, citations préservées dans la langue du document source." },
        { q: "Comment fonctionne l'intégration MCP avec Claude Desktop ou Cursor ?",
          a: "Nous fournissons un serveur enterprise-kb (Laravel\\Mcp ^0.7) avec 10 outils : 5 retrieval (hybrid search, graph expansion…) et 5 canonical/promote. Configurez-le comme serveur MCP dans le client et accédez au graphe tenant-scoped." },
        { q: "Quelle invasivité pour onboarder une codebase existante ?",
          a: "Minimale : une GitHub Action sur un repo de docs (Markdown brut suffit). L'ingestion est idempotente sur SHA-256, donc les push suivants ne dupliquent pas. La promotion canonique reste human-gated, vous partez de zéro et grandissez." },
      ],
    },

    cta: {
      eyebrow: "Prêt à démarrer",
      titleHtml:
        'La couche de grounding<br/>qui <span style="font-family:Instrument Serif, serif;font-style:italic;color:var(--mint)">aurait dû exister</span> dès le day one.',
      sub: "Auto-hébergez gratuitement en 10 minutes, ou laissez-nous le gérer. Multi-tenant, audit-ready, compatible MCP.",
      primary: "Demander une démo",
      secondary: "Star sur GitHub",
    },

    footer: {
      tagline: "RAG enterprise avec knowledge graph canonique. Self-host first, MCP-native, multi-tenant by schema.",
      colProduct: "Produit",
      colResources: "Ressources",
      colCompany: "Entreprise",
      colLegal: "Légal",
      product: { features: "Fonctionnalités", how: "Fonctionnement", security: "Sécurité", usecases: "Cas d'usage" },
      resources: { docs: "Docs", mcp: "Setup MCP", changelog: "Changelog", status: "Status" },
      company: { contact: "Contact", blog: "Blog", careers: "Carrières", sales: "Ventes" },
      legal: { privacy: "Confidentialité", terms: "Conditions", dpa: "DPA", securitytxt: "security.txt" },
      copyright: "© 2026 AskMyDoc · Construit avec Laravel 13, PostgreSQL, pgvector et des convictions têtues.",
      version: "v1.4.2 · MIT (core) · Licence commerciale disponible",
    },

    langSwitch: "Langue",
  },

  /* ============================== GERMAN ================================ */
  de: {
    htmlLang: "de",
    metaDescription:
      "Typisierte Knowledge Base, kanonische Kompilierung, hybride Retrieval mit Rejected-Approach-Injection. Multi-Tenant, Multi-Provider, Self-Host. Die Enterprise-Grounding-Layer für LLMs.",
    pageTitle: "AskMyDoc — Enterprise RAG, das Fehler nicht wiederholt",

    nav: {
      product: "Produkt",
      how: "Funktionsweise",
      security: "Sicherheit",
      usecases: "Use Cases",
      faq: "FAQ",
      docs: "Docs",
      github: "GitHub",
      signin: "Anmelden",
      bookdemo: "Demo buchen",
    },

    hero: {
      eyebrow: "v1.4 · canonical KB + MCP-Server",
      titleHtml:
        'Enterprise RAG,<br/>das Fehler <span class="accent">nicht wiederholt</span> — <span class="strike">nie wieder</span>',
      sub:
        "Typisierte Knowledge Base, kanonische Kompilierung, Injection verworfener Ansätze. Multi-Tenant, Multi-Provider, Self-Host. Die Grounding-Layer, die es von Tag eins hätte geben sollen.",
      ctaPrimary: "Demo testen",
      ctaSecondary: "Self-Host kostenlos",
      metaProviders: "OpenAI · Anthropic · Gemini · OpenRouter · Regolo",
      metaDb: "Postgres + pgvector",
      metaStack: "Laravel 13 · React",
      graphProject: "Projekt",
      graphTenant: "Tenant",
      graphLive: "live",
    },

    graphPanel: {
      nodeTypesHeader: "Knotentypen · 9",
      edgesHeader: "Kanten · 10 Typen",
      graphExpansionHeader: "Graph-Expansion",
      row1hop: "1-Hop-Nachbarn",
      rowRejectedInj: "Rejected-Approach-Inj.",
      rowCompositeFk: "composite Tenant-FK",
      floatingNodes: "Knoten",
      floatingEdges: "Kanten",
      floatingRejected: "verworfen",
      floatingExpansion: "Expansion",
    },

    trustLabel: "Stack",

    problem: {
      eyebrow: "Das Problem",
      titleHtml:
        'Vanilla-RAG halluziniert, wiederholt<br/><span class="accent">bereits verworfene Ansätze</span> — ohne Governance.',
      subHtml:
        'Embedding + Top-k ist kein Gedächtnis. Es ist ein Index. Ein Gedächtnis weiß, was <span class="ink-1">kanonisch</span> ist, was <span class="ink-1">verworfen</span> wurde, was wovon <span class="ink-1">abhängt</span> — und schlägt nicht erneut den Fehler vor, den Sie vor sechs Monaten behoben haben.',
      badHead: "✕ Ohne AskMyDoc",
      badTitle: "Derselbe Fehler, alle sechs Monate.",
      badBody:
        "Ein neuer Entwickler fragt den internen Chatbot nach dem Ansatz für X. Das Embedding zieht zwei READMEs, ein altes Ticket und einen verwaisten Kommentar heran. Es schlägt dieselbe Lösung vor, die Sie nach einem Prod-Incident bereits verworfen haben.",
      goodHead: "✓ Mit AskMyDoc",
      goodTitle: "Der Graph weiß, was verworfen wurde.",
      goodBodyHtml:
        'Die Query löst eine 1-Hop-Expansion am Knoten <code class="mono ink-1">module:auth</code> aus. Sie findet einen <code class="mono coral">rejected-approach</code>-Knoten, verbunden via <code class="mono ink-1"> supersedes</code>. Das LLM erhält das Antipattern im Kontext und die Antwort beginnt mit: „Diesen Ansatz nicht verwenden — wurde bereits versucht, siehe INC-2025-04."',
    },

    retrieval: {
      query: "Wie handhaben wir Refresh-Tokens im acme-prod Tenant?",
      headPath: "POST /api/kb/query",
      headFusion: "hybrid (0,6·v + 0,3·k + 0,1·h)",
      live: "live",
      pipeVector: "vector · pgvector",
      pipeKeyword: "keyword · FTS",
      pipeRerank: "rerank · head",
      answerHead: "↳ grounded Antwort",
      answerMetaWaiting: "warte…",
      answerMetaDone: "847ms · 3 Citations · 1 verworfen",
      answerBodyHtml:
        'Für <span class="mono ink-1">acme-prod</span> folgt der Refresh der in <span class="cite">ADR-0011</span> definierten Rotation: 7-Tage-Token, single-use, widerrufen via <span class="mono ink-1">tenant_id</span>-Scope. Siehe Runbook <span class="cite">auth/refresh-flow</span> und verbundener Incident <span class="cite">INC-2025-04</span>.',
      rejectedHtml:
        '<b>Nicht vorschlagen</b>: langlebige JWTs ohne Rotation — verworfener Ansatz (supersedes m:auth)',
    },

    features: {
      eyebrow: "Produkt",
      titleHtml: 'Sechs Fähigkeiten, die <span class="accent">Vanilla-RAG</span> nicht hat.',
      sub: "Eine Plattform — opinionated, wo es zählt, agnostisch, wo sich Dinge ändern.",
      items: [
        {
          title: "Hybrid Retrieval mit Reranking",
          body: "Fusion 0,6·Vector + 0,3·Keyword + 0,1·Head. pgvector neben Postgres FTS, ohne externe Services. Embedding-Cache mit integriertem LRU-Pruning.",
          meta: ["pgvector", "FTS", "rerank"],
        },
        {
          title: "Kanonischer Knowledge Graph",
          body: "9 Knotentypen, 10 Kantentypen. YAML-Frontmatter + Wikilinks, 1-Hop-Expansion zur Query-Zeit, automatische Injection verworfener Ansätze.",
          meta: ["typisierte Knoten", "rejected-approach"],
        },
        {
          title: "Multi-Provider, kein Lock-in",
          body: "OpenAI, Anthropic, Gemini, OpenRouter, Regolo — orchestriert mit raw Http::, ohne SDK-Black-Box. Chat- und Embedding-Provider separat konfigurierbar.",
          meta: ["5 Provider", "no SDK"],
        },
        {
          title: "Strukturelles Multi-Tenant",
          body: "tenant_id auf 17+ Tabellen, composite Uniques für slug/doc_id, composite FKs machen Cross-Tenant-Kanten auf Schema-Ebene unmöglich.",
          meta: ["isolation by schema"],
        },
        {
          title: "Vollständige Admin-SPA",
          body: "KPI-Dashboard, Spatie-RBAC, KB-Tree-Explorer + Inline-Editor, Graph-Viewer, PDF-Export, 5-Tab-Log-Viewer und whitelisted Artisan-Runner.",
          meta: ["React + TanStack"],
        },
        {
          title: "MCP-Server fertig",
          body: "enterprise-kb-Server mit 10 Tools (5 Retrieval + 5 canonical/promote). Anschluss an Claude Desktop, Cursor oder jeden MCP-kompatiblen Client.",
          meta: ["10 Tools", "Laravel MCP 0.7"],
        },
      ],
    },

    how: {
      eyebrow: "Funktionsweise",
      titleHtml: 'Drei Schritte. <span class="accent">Keine Magie.</span>',
      step1: {
        label: "Ingest",
        title: "Git push → Auto-Ingest.",
        body: "Eine GitHub Action führt ein idempotentes Upsert auf (project_key, source_path, version_hash) aus. Der Fence-aware Chunker folgt den Markdown-Sektionen.",
        visual: [
          { cls: "dim", text: "› git push origin main" },
          { cls: "info", text: "→ GH Action: ingest-to-askmydocs" },
          { cls: "dim", text: "  sha256: e7d2…91a" },
          { cls: "", text: "  POST /api/kb/ingest" },
          { cls: "ok", text: "✓ 14 Chunks upserted" },
          { cls: "ok", text: "✓ idempotent (no dup)" },
          { cls: "dim", text: "  job: IngestDocumentJob" },
        ],
      },
      step2: {
        label: "Compile",
        title: "Human-gated Promotion.",
        body: "Ein LLM schlägt kanonische Kandidaten vor (no-write). Ein Mensch validiert. Erst beim Commit gelangen Knoten mit unveränderlichem Audit-Trail in den Graph.",
        visual: [
          { cls: "", text: "/promotion/suggest" },
          { cls: "dim", text: "  ↳ 3 Kandidaten" },
          { cls: "dim", text: "    · decision: ADR-0011" },
          { cls: "dim", text: "    · rejected: long-lived JWT" },
          { cls: "dim", text: "    · runbook: refresh-flow" },
          { cls: "info", text: "  Review durch alice@acme" },
          { cls: "ok", text: "✓ promote → canonical" },
          { cls: "dim", text: "  audit: kb_canonical_audit" },
        ],
      },
      step3: {
        label: "Answer",
        title: "Grounded. Zitiert. Auditiert.",
        body: "Hybrid Retrieval + 1-Hop-Graph-Expansion. Verworfene Ansätze gelangen in den Kontext. Refusal-Path bei unzureichendem Grounding.",
        visual: [
          { cls: "dim", text: "› query „refresh token acme\"" },
          { cls: "info", text: "  hybrid: 9 Hits (v+k+h)" },
          { cls: "info", text: "  graph: +2 Nachbarn (1-hop)" },
          { cls: "coral", text: "  ⚠ 1 verworfener Ansatz injiziert" },
          { cls: "", text: "  llm: anthropic / sonnet" },
          { cls: "ok", text: "✓ Antwort · 3 Citations" },
          { cls: "dim", text: "  audit geloggt · 847ms" },
        ],
      },
    },

    usecases: {
      eyebrow: "Use Cases",
      titleHtml: 'Wo es <span class="accent">wirklich funktioniert</span>.',
      items: [
        {
          icon: "Book",
          title: "Engineering-Docs & ADR-Repository",
          body: "Verwandelt READMEs, ADRs und RFCs in einen typisierten kanonischen Graph. Abgelöste Entscheidungen werden via supersedes verlinkt — nicht verloren.",
          tags: ["ADR", "RFC", "Runbook"],
        },
        {
          icon: "Support",
          title: "Customer-Support-Knowledge-Base",
          body: "Grounded Antworten mit Citations, Refusal-Path bei fehlendem Grounding, Filter-Presets pro Produktkategorie und Version.",
          tags: ["Citations", "Refusal", "Filter"],
        },
        {
          icon: "Scale",
          title: "Compliance & Patent-Box-Audit",
          body: "Unveränderlicher Audit-Trail bei jeder kanonischen Promotion. Soft Delete + konfigurierbare Retention. Zertifizierbarer PDF-Export.",
          tags: ["Audit", "Retention", "PDF"],
        },
        {
          icon: "Brain",
          title: "Multi-Team-LLM-Grounding",
          body: "MCP-Server mit 10 Tools. Claude Desktop und Cursor sprechen direkt mit Ihrem kanonischen Graph, mit Tenant-Isolation.",
          tags: ["MCP", "Claude", "Cursor"],
        },
      ],
    },

    security: {
      eyebrow: "Security & Compliance",
      titleHtml: 'Isolation <span class="accent">ist strukturell</span>, nicht versprochen.',
      items: [
        { h: "Isolation", t: "Tenant by Schema", b: "tenant_id auf 17+ Tabellen, composite FKs. Cross-Tenant-Kanten strukturell unmöglich — nicht durch App-Check." },
        { h: "Audit", t: "Unveränderlicher Trail", b: "kb_canonical_audit protokolliert jede Promotion. Destruktive Commands erfordern ein DB-backed Single-Use-Token." },
        { h: "Retention", t: "Soft Delete + Sweep", b: "Konfigurierbare Retention (Default 30 Tage). Nächtlicher Scheduler bereinigt Embeddings, Chat-Logs und gelöschte Records." },
        { h: "RBAC", t: "Spatie + Sanctum", b: "Granulare Rollen und Permissions. Sanctum für API-Auth, RBAC auf jedem Admin-Endpoint. Kein Bypass." },
        { h: "Provider", t: "Keine SDK-Black-Box", b: "Raw Http:: zu jedem Provider. Keine opaken Dependencies, keine versteckte Telemetrie, keine indirekten Data-Leaks." },
      ],
    },

    faq: {
      eyebrow: "FAQ",
      titleHtml: 'Häufige <span class="accent">Fragen</span>.',
      items: [
        { q: "Welche LLMs und Provider werden unterstützt?",
          a: "OpenAI, Anthropic, Gemini, OpenRouter und Regolo für Chat. Chat-Provider und Embedding-Provider separat konfigurierbar — kein SDK, nur raw Http::." },
        { q: "Kann ich den Embedding-Provider ohne Re-Index wechseln?",
          a: "Nein, und niemand kann das: Embedding-Dimensionen unterscheiden sich zwischen Providern (z. B. 1536 vs. 768 vs. 3072). Beim Wechsel ist Re-Indexing unvermeidbar. Wir handhaben es mit idempotenten Batch-Jobs — siehe README „Embedding dimension gotcha\"." },
        { q: "Funktioniert es on-prem oder air-gapped?",
          a: "Ja. AskMyDoc ist self-host first: Laravel 13 + PostgreSQL + pgvector laufen überall. Für air-gapped Chat einfach auf einen on-prem Provider (z. B. vLLM, Ollama via OpenAI-kompatibel) zeigen." },
        { q: "Mehrsprachige Unterstützung?",
          a: "Ja. Integrierte Auto-Übersetzung (Laravel-Skill), Sprach-Filterbar in Conversations, Citations bleiben in der Sprache des Quelldokuments erhalten." },
        { q: "Wie funktioniert die MCP-Integration mit Claude Desktop oder Cursor?",
          a: "Wir liefern einen enterprise-kb-Server (Laravel\\Mcp ^0.7) mit 10 Tools: 5 Retrieval (Hybrid Search, Graph Expansion …) und 5 canonical/promote. Konfiguriert als MCP-Server im Client erhalten Sie tenant-scoped Graph-Zugriff." },
        { q: "Wie invasiv ist das Onboarding einer bestehenden Codebase?",
          a: "Minimal: eine GitHub Action auf einem Docs-Repo (auch reines Markdown). Ingestion ist SHA-256-idempotent, also dupliziert nichts. Kanonische Promotion bleibt human-gated — bei null starten und wachsen." },
      ],
    },

    cta: {
      eyebrow: "Bereit zum Start",
      titleHtml:
        'Die Grounding-Layer,<br/>die <span style="font-family:Instrument Serif, serif;font-style:italic;color:var(--mint)">es geben sollte</span> — seit Tag eins.',
      sub: "Kostenlos in 10 Minuten self-hosten — oder uns machen lassen. Multi-Tenant, audit-ready, MCP-kompatibel.",
      primary: "Demo buchen",
      secondary: "Star auf GitHub",
    },

    footer: {
      tagline: "Enterprise RAG mit kanonischem Knowledge Graph. Self-host first, MCP-native, multi-tenant by schema.",
      colProduct: "Produkt",
      colResources: "Ressourcen",
      colCompany: "Unternehmen",
      colLegal: "Rechtliches",
      product: { features: "Features", how: "Funktionsweise", security: "Sicherheit", usecases: "Use Cases" },
      resources: { docs: "Docs", mcp: "MCP-Setup", changelog: "Changelog", status: "Status" },
      company: { contact: "Kontakt", blog: "Blog", careers: "Karriere", sales: "Vertrieb" },
      legal: { privacy: "Datenschutz", terms: "AGB", dpa: "AVV", securitytxt: "security.txt" },
      copyright: "© 2026 AskMyDoc · Gebaut mit Laravel 13, PostgreSQL, pgvector und sturen Überzeugungen.",
      version: "v1.4.2 · MIT (Core) · Kommerzielle Lizenz verfügbar",
    },

    langSwitch: "Sprache",
  },

  /* ============================== SPANISH =============================== */
  es: {
    htmlLang: "es",
    metaDescription:
      "Base de conocimiento tipada, compilación canónica, retrieval híbrido con inyección de enfoques rechazados. Multi-tenant, multi-provider, self-host. La capa de grounding enterprise para LLM.",
    pageTitle: "AskMyDoc — RAG enterprise que no repite los errores",

    nav: {
      product: "Producto",
      how: "Cómo funciona",
      security: "Seguridad",
      usecases: "Casos de uso",
      faq: "FAQ",
      docs: "Docs",
      github: "GitHub",
      signin: "Iniciar sesión",
      bookdemo: "Reservar demo",
    },

    hero: {
      eyebrow: "v1.4 · KB canónica + servidor MCP",
      titleHtml:
        'RAG enterprise<br/>que <span class="accent">no repite</span> los <span class="strike">errores</span>',
      sub:
        "Base de conocimiento tipada, compilación canónica, inyección de enfoques rechazados. Multi-tenant, multi-provider, self-host. La capa de grounding que debería haber estado desde el día uno.",
      ctaPrimary: "Probar la demo",
      ctaSecondary: "Self-host gratis",
      metaProviders: "OpenAI · Anthropic · Gemini · OpenRouter · Regolo",
      metaDb: "Postgres + pgvector",
      metaStack: "Laravel 13 · React",
      graphProject: "proyecto",
      graphTenant: "tenant",
      graphLive: "live",
    },

    graphPanel: {
      nodeTypesHeader: "tipos de nodo · 9",
      edgesHeader: "edges · 10 tipos",
      graphExpansionHeader: "expansión del grafo",
      row1hop: "vecinos 1-hop",
      rowRejectedInj: "iny. enfoque rechazado",
      rowCompositeFk: "FK composite tenant",
      floatingNodes: "nodos",
      floatingEdges: "edges",
      floatingRejected: "rechazado",
      floatingExpansion: "expansión",
    },

    trustLabel: "Stack",

    problem: {
      eyebrow: "El problema",
      titleHtml:
        'El RAG vanilla alucina, repite<br/><span class="accent">enfoques ya rechazados</span>, sin gobernanza.',
      subHtml:
        'Embedding + top-k no es una memoria. Es un índice. Una memoria sabe qué es <span class="ink-1">canónico</span>, qué fue <span class="ink-1">rechazado</span>, qué <span class="ink-1">depende</span> de qué — y no vuelve a sugerir el error que arreglaste hace seis meses.',
      badHead: "✕ Sin AskMyDoc",
      badTitle: "El mismo error, cada seis meses.",
      badBody:
        "Un nuevo dev pregunta al chatbot interno el enfoque para X. El embedding recupera dos README, un ticket viejo y un comentario abandonado. Sugiere la misma solución que ya rechazasteis tras un incidente en producción.",
      goodHead: "✓ Con AskMyDoc",
      goodTitle: "El grafo sabe lo que fue descartado.",
      goodBodyHtml:
        'La query dispara una expansión 1-hop sobre el nodo <code class="mono ink-1">module:auth</code>. Encuentra un nodo <code class="mono coral">rejected-approach</code> conectado vía <code class="mono ink-1"> supersedes</code>. El LLM recibe el antipatrón en contexto y la respuesta empieza con: «No usar este enfoque, ya fue intentado — ver INC-2025-04.»',
    },

    retrieval: {
      query: "¿Cómo gestionamos el refresh token en el tenant acme-prod?",
      headPath: "POST /api/kb/query",
      headFusion: "híbrido (0,6·v + 0,3·k + 0,1·h)",
      live: "live",
      pipeVector: "vector · pgvector",
      pipeKeyword: "keyword · FTS",
      pipeRerank: "rerank · head",
      answerHead: "↳ respuesta grounded",
      answerMetaWaiting: "esperando…",
      answerMetaDone: "847ms · 3 citas · 1 rechazado",
      answerBodyHtml:
        'Para <span class="mono ink-1">acme-prod</span>, el refresh sigue la rotación definida en <span class="cite">ADR-0011</span>: token de 7 días, single-use, revocado vía <span class="mono ink-1">tenant_id</span> scope. Ver runbook <span class="cite">auth/refresh-flow</span> e incidente relacionado <span class="cite">INC-2025-04</span>.',
      rejectedHtml:
        '<b>No proponer</b> JWT long-lived sin rotation — enfoque rechazado (supersedes m:auth)',
    },

    features: {
      eyebrow: "Producto",
      titleHtml: 'Seis capacidades que <span class="accent">el RAG vanilla</span> no tiene.',
      sub: "Una sola plataforma, opinionada en lo que importa, agnóstica en lo que cambia.",
      items: [
        {
          title: "Retrieval híbrido con reranking",
          body: "Fusión 0,6·vector + 0,3·keyword + 0,1·head. pgvector junto a Postgres FTS, sin servicios externos. Caché de embeddings con LRU pruning integrado.",
          meta: ["pgvector", "FTS", "rerank"],
        },
        {
          title: "Knowledge graph canónico",
          body: "9 tipos de nodo, 10 tipos de edge. Frontmatter YAML + wikilinks, expansión 1-hop a tiempo de query, inyección automática de enfoques rechazados.",
          meta: ["nodos tipados", "rejected-approach"],
        },
        {
          title: "Multi-provider, cero lock-in",
          body: "OpenAI, Anthropic, Gemini, OpenRouter, Regolo — orquestados con raw Http::, sin SDK black-box. Chat y embedding configurables por separado.",
          meta: ["5 providers", "no SDK"],
        },
        {
          title: "Multi-tenant estructural",
          body: "tenant_id en 17+ tablas, uniques composite para slug/doc_id, FK composite que hacen los edges cross-tenant imposibles a nivel de esquema.",
          meta: ["isolation by schema"],
        },
        {
          title: "SPA admin completa",
          body: "Dashboard KPI, RBAC Spatie, explorador KB + editor inline, visor de grafo, export PDF, log viewer de 5 pestañas y runner Artisan whitelisted.",
          meta: ["React + TanStack"],
        },
        {
          title: "Servidor MCP listo",
          body: "Servidor enterprise-kb con 10 herramientas (5 retrieval + 5 canonical/promote). Se conecta a Claude Desktop, Cursor o cualquier cliente MCP-compatible.",
          meta: ["10 tools", "Laravel MCP 0.7"],
        },
      ],
    },

    how: {
      eyebrow: "Cómo funciona",
      titleHtml: 'Tres pasos. <span class="accent">Sin magia.</span>',
      step1: {
        label: "Ingest",
        title: "Git push → auto-ingest.",
        body: "Una GitHub Action ejecuta un upsert idempotente sobre (project_key, source_path, version_hash). El chunker fence-aware sigue las secciones del markdown.",
        visual: [
          { cls: "dim", text: "› git push origin main" },
          { cls: "info", text: "→ GH Action: ingest-to-askmydocs" },
          { cls: "dim", text: "  sha256: e7d2…91a" },
          { cls: "", text: "  POST /api/kb/ingest" },
          { cls: "ok", text: "✓ 14 chunks upserted" },
          { cls: "ok", text: "✓ idempotente (no dup)" },
          { cls: "dim", text: "  job: IngestDocumentJob" },
        ],
      },
      step2: {
        label: "Compile",
        title: "Promoción human-gated.",
        body: "Un LLM sugiere candidatos canónicos (no-write). Un humano valida. Solo al commit los nodos entran al grafo con audit trail inmutable.",
        visual: [
          { cls: "", text: "/promotion/suggest" },
          { cls: "dim", text: "  ↳ 3 candidatos" },
          { cls: "dim", text: "    · decision: ADR-0011" },
          { cls: "dim", text: "    · rejected: long-lived JWT" },
          { cls: "dim", text: "    · runbook: refresh-flow" },
          { cls: "info", text: "  revisión por alice@acme" },
          { cls: "ok", text: "✓ promote → canonical" },
          { cls: "dim", text: "  audit: kb_canonical_audit" },
        ],
      },
      step3: {
        label: "Answer",
        title: "Grounded. Citada. Auditada.",
        body: "Retrieval híbrido + expansión 1-hop del grafo. Los enfoques rechazados entran en contexto. Refusal path cuando no hay grounding suficiente.",
        visual: [
          { cls: "dim", text: "› query \"refresh token acme\"" },
          { cls: "info", text: "  hybrid: 9 hits (v+k+h)" },
          { cls: "info", text: "  graph: +2 vecinos (1-hop)" },
          { cls: "coral", text: "  ⚠ 1 enfoque rechazado inyectado" },
          { cls: "", text: "  llm: anthropic / sonnet" },
          { cls: "ok", text: "✓ respuesta · 3 citas" },
          { cls: "dim", text: "  audit logueado · 847ms" },
        ],
      },
    },

    usecases: {
      eyebrow: "Casos de uso",
      titleHtml: 'Donde <span class="accent">funciona de verdad</span>.',
      items: [
        {
          icon: "Book",
          title: "Docs de ingeniería & repositorio ADR",
          body: "Transforma README, ADR y RFC en un grafo canónico tipado. Las decisiones superadas se enlazan vía supersedes — no se pierden.",
          tags: ["ADR", "RFC", "Runbook"],
        },
        {
          icon: "Support",
          title: "Base de conocimiento soporte cliente",
          body: "Respuestas grounded con citas, refusal path cuando falta grounding, presets de filtro por categoría y versión de producto.",
          tags: ["Citas", "Refusal", "Filtros"],
        },
        {
          icon: "Scale",
          title: "Compliance & auditoría Patent Box",
          body: "Audit trail inmutable en cada promoción canónica. Soft delete + retention configurable. Export PDF certificable.",
          tags: ["Audit", "Retention", "PDF"],
        },
        {
          icon: "Brain",
          title: "Grounding LLM multi-equipo",
          body: "Servidor MCP con 10 herramientas. Claude Desktop y Cursor hablan directamente con tu grafo canónico, con aislamiento por tenant.",
          tags: ["MCP", "Claude", "Cursor"],
        },
      ],
    },

    security: {
      eyebrow: "Seguridad & compliance",
      titleHtml: 'El aislamiento <span class="accent">es estructural</span>, no prometido.',
      items: [
        { h: "Isolation", t: "Tenant por esquema", b: "tenant_id en 17+ tablas, FK composite. Edges cross-tenant imposibles estructuralmente, no por check aplicativo." },
        { h: "Audit", t: "Trail inmutable", b: "kb_canonical_audit registra cada promoción. Los comandos destructivos requieren un token de un solo uso DB-backed." },
        { h: "Retention", t: "Soft delete + sweep", b: "Retention configurable (30d por defecto). Scheduler nocturno purga embeddings, chat-logs y registros eliminados." },
        { h: "RBAC", t: "Spatie + Sanctum", b: "Roles y permisos granulares. Sanctum para API auth, RBAC en cada endpoint admin. Sin bypass." },
        { h: "Providers", t: "Sin SDK black box", b: "Raw Http:: hacia cada provider. Sin dependencias opacas, sin telemetría oculta, sin fugas de datos indirectas." },
      ],
    },

    faq: {
      eyebrow: "FAQ",
      titleHtml: 'Preguntas <span class="accent">recurrentes</span>.',
      items: [
        { q: "¿Qué LLMs y providers soportan?",
          a: "OpenAI, Anthropic, Gemini, OpenRouter y Regolo para chat. Chat provider y embedding provider son configurables por separado — sin SDK, solo raw Http::." },
        { q: "¿Puedo cambiar el embedding provider sin reindexar?",
          a: "No, y nadie puede: la dimensión del embedding cambia entre providers (ej. 1536 vs 768 vs 3072). Al cambiar, reindexar es inevitable. Lo gestionamos con jobs batch idempotentes — ver README «Embedding dimension gotcha»." },
        { q: "¿Funciona on-prem o air-gapped?",
          a: "Sí. AskMyDoc es self-host first: Laravel 13 + PostgreSQL + pgvector corren en cualquier sitio. Para air-gapped, apuntar el chat a un provider on-prem (ej. vLLM, Ollama vía OpenAI-compatible)." },
        { q: "¿Soporte multi-idioma?",
          a: "Sí. Auto-traducción integrada (skill Laravel), filter bar por idioma en las conversaciones, citas preservadas en el idioma del documento fuente." },
        { q: "¿Cómo funciona la integración MCP con Claude Desktop o Cursor?",
          a: "Incluimos un servidor enterprise-kb (Laravel\\Mcp ^0.7) con 10 herramientas: 5 de retrieval (hybrid search, graph expansion, etc.) y 5 de canonical/promote. Lo configuras como servidor MCP en el cliente y obtienes acceso al grafo tenant-scoped." },
        { q: "¿Cómo de invasivo es el onboarding de una codebase existente?",
          a: "Mínimo: una GitHub Action sobre un repo de docs (Markdown puro vale). La ingestion es idempotente sobre SHA-256, así que los push posteriores no duplican. La promoción canónica sigue siendo human-gated — empezáis de cero y crecéis." },
      ],
    },

    cta: {
      eyebrow: "Listo para empezar",
      titleHtml:
        'La capa de grounding<br/>que <span style="font-family:Instrument Serif, serif;font-style:italic;color:var(--mint)">debería haber estado</span> desde el day one.',
      sub: "Self-host gratis en 10 minutos, o deja que la gestionemos nosotros. Multi-tenant, audit-ready, MCP-compatible.",
      primary: "Reservar demo",
      secondary: "Star en GitHub",
    },

    footer: {
      tagline: "RAG enterprise con knowledge graph canónico. Self-host first, MCP-native, multi-tenant by schema.",
      colProduct: "Producto",
      colResources: "Recursos",
      colCompany: "Empresa",
      colLegal: "Legal",
      product: { features: "Características", how: "Cómo funciona", security: "Seguridad", usecases: "Casos de uso" },
      resources: { docs: "Docs", mcp: "Setup MCP", changelog: "Changelog", status: "Status" },
      company: { contact: "Contacto", blog: "Blog", careers: "Empleo", sales: "Ventas" },
      legal: { privacy: "Privacidad", terms: "Términos", dpa: "DPA", securitytxt: "security.txt" },
      copyright: "© 2026 AskMyDoc · Construido con Laravel 13, PostgreSQL, pgvector y opiniones obstinadas.",
      version: "v1.4.2 · MIT (core) · Licencia comercial disponible",
    },

    langSwitch: "Idioma",
  },
};

window.LANGS = LANGS;
window.I18N = I18N;

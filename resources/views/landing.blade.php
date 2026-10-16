@php
    $designTheme = app(\App\Services\Appearance\InstallationAppearance::class)->theme();
    $editorialBase = $designTheme === 'humanistic' ? '/editorial' : '/editorial/themes/'.$designTheme;
@endphp
<!doctype html>
<html lang="it" data-design-theme="{{ $designTheme }}" data-theme="dark">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0e1411">
    <title>AskMyDocs — Il sapere che fa la differenza.</title>
    <meta name="description" content="La piattaforma AI che connette documenti, persone e conoscenza. Risposte con fonti, controllo dei dati e un nuovo vantaggio per il vostro team.">
    <meta property="og:title" content="AskMyDocs — Il sapere che fa la differenza.">
    <meta property="og:description" content="La piattaforma AI che connette documenti, persone e conoscenza. Risposte con fonti, controllo dei dati e un nuovo vantaggio per il vostro team.">
    <meta property="og:type" content="website">
    <meta property="og:image" content="{{ $editorialBase }}/knowledge-night.webp" data-editorial-og="knowledge">
    <link rel="icon" href="/landing/favicon.svg" type="image/svg+xml">
    <link rel="preload" href="{{ $editorialBase }}/knowledge-night.webp" as="image" imagesrcset="{{ $editorialBase }}/knowledge-night-small.webp 640w, {{ $editorialBase }}/knowledge-night.webp 1280w" imagesizes="100vw" fetchpriority="high" data-editorial-preload="knowledge">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin="">
    <link href="https://fonts.googleapis.com/css2?family=Geist:wght@400;500;600;700&amp;family=Geist+Mono:wght@400;500;600&amp;family=DM+Mono:wght@400;500&amp;family=Instrument+Serif:ital@0;1&amp;display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/landing/styles.css?v=design-themes-2">
    <link rel="stylesheet" href="/landing/theme.css?v=design-themes-2">
    @viteReactRefresh
    @vite('frontend/src/landing-select.tsx')
    <script type="module" src="/landing/app.js?v=design-themes-2"></script>
  </head>
  <body class="landing-page">
    <a class="skip-link" href="#main" data-i18n="skip">Vai al contenuto</a>
    <header class="header">
      <a class="brand" href="#top" aria-label="AskMyDocs"><svg class="brand-mark" viewBox="0 0 32 32" aria-hidden="true"><path d="M4 25 16 5l12 20H4ZM10 25l6-10 6 10M16 5v10"></path></svg><span>AskMyDocs<span class="brand-period">.</span></span></a>
      <nav class="desktop-nav" aria-label="Navigazione principale" data-i18n-aria="navigation">
        <a href="#vision" data-i18n="nav.vision">La visione</a>
        <a href="#experience" data-i18n="nav.experience">L’esperienza</a>
        <a href="#possibilities" data-i18n="nav.possibilities">Le possibilità</a>
      </nav>
      <div class="header-actions">
        <div class="language-control">
          <svg viewBox="0 0 24 24" aria-hidden="true">
            <circle cx="12" cy="12" r="9"></circle>
            <path d="M3 12h18M12 3a20 20 0 0 1 0 18 20 20 0 0 1 0-18"></path>
          </svg>
          <label id="language-label" class="sr-only" for="language" data-i18n="language">Lingua</label>
          <div id="language-select-root" data-locale="it"></div>
        </div>
        <button type="button" class="btn btn-quiet theme-toggle" id="theme-toggle" data-i18n-aria="theme" aria-label="Modalità chiara" aria-pressed="false">
          <svg class="theme-icon-light" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M2 12h2m16 0h2M5 5l1.5 1.5m11 11L19 19M5 19l1.5-1.5m11-11L19 5"/></svg>
          <svg class="theme-icon-dark" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M20.8 14a9 9 0 0 1-10.8-10.8A9 9 0 1 0 20.8 14Z"/></svg>
        </button>
        <a class="login-link" href="/login" data-i18n="nav.login">Accedi</a>
        <a class="btn btn-primary header-cta" href="/app/me"><span data-i18n="nav.demo">Entra nella demo</span><span aria-hidden="true"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M7 17 17 7M7 7h10v10"/></svg></span></a>
        <button class="btn btn-quiet menu-toggle" aria-controls="mobile-nav" aria-expanded="false" data-i18n-aria="menu" aria-label="Apri il menu"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 8h16M4 16h16"></path></svg></button>
      </div>
      <nav id="mobile-nav" class="mobile-nav" hidden="" aria-label="Navigazione principale" data-i18n-aria="navigation">
        <a href="#vision" data-i18n="nav.vision">La visione</a>
        <a href="#experience" data-i18n="nav.experience">L’esperienza</a>
        <a href="#possibilities" data-i18n="nav.possibilities">Le possibilità</a>
        <a href="#trust" data-i18n="nav.trust">La fiducia</a>
        <a href="/login" data-i18n="nav.login">Accedi</a>
      </nav>
    </header>
    <main id="main">
      <section class="hero" id="top" aria-labelledby="hero-title">
        <div class="hero-copy">
          <p class="eyebrow"><span class="small-star" aria-hidden="true"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M12 3v18M3 12h18M6 6l12 12M6 18 18 6"/></svg></span><span data-i18n="hero.eyebrow">La vostra conoscenza. Una nuova intelligenza.</span></p>
          <h1 id="hero-title"><span data-i18n="hero.title">Il sapere che</span><br><span data-i18n="hero.title2">fa la</span><br><em data-i18n="hero.emphasis">differenza.</em></h1>
          <p class="hero-description" data-i18n="hero.description">Documenti, persone, idee. Connettete ciò che la vostra organizzazione sa e trasformatelo in risposte, con le fonti. L’AI, all’altezza del vostro mondo.</p>
          <div class="hero-actions">
            <a class="btn btn-primary btn-lg" href="#experience"><span data-i18n="hero.cta">Scopri l’esperienza</span><span aria-hidden="true"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M7 17 17 7M7 7h10v10"/></svg></span></a>
            <a class="text-link" href="#vision"><span data-i18n="hero.secondary">La nostra visione</span><span aria-hidden="true"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M12 5v14m-6-6 6 6 6-6"/></svg></span></a>
          </div>
          <div class="hero-note">
            <span class="status-dot" aria-hidden="true"></span>
            <span data-i18n="hero.note">La vostra conoscenza. Le vostre fonti. Il vostro controllo.</span>
          </div>
        </div>
        <div class="hero-visual">
          <img class="hero-image" src="{{ $editorialBase }}/knowledge-night.webp" srcset="{{ $editorialBase }}/knowledge-night-small.webp 640w, {{ $editorialBase }}/knowledge-night.webp 1280w" sizes="100vw" width="1280" height="853" alt="Illustrazione di una biblioteca aperta, tra libri e percorsi di conoscenza" data-i18n-alt="images.hero" data-editorial-artwork="knowledge" fetchpriority="high">
          <div class="image-index">
            <span>01 / 03</span>
            <span data-i18n="hero.caption">Il sapere umano. Una nuova prospettiva.</span>
          </div>
          <a href="#experience" class="hero-insight"><span class="insight-mark" aria-hidden="true"><svg viewBox="0 0 44 44" aria-hidden="true"><path d="m9 12 13 10 13-10M22 22v15M9 12v20l13 5 13-5V12" opacity=".5"></path><circle cx="9" cy="12" r="4"></circle><circle cx="35" cy="12" r="4"></circle><circle cx="22" cy="22" r="4"></circle><circle cx="22" cy="37" r="3"></circle></svg></span><div><p data-i18n="hero.cardLabel">Tutto connesso.</p><span data-i18n="hero.cardText">Ogni risposta, la sua fonte.</span></div><span class="insight-arrow" aria-hidden="true"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M7 17 17 7M7 7h10v10"/></svg></span></a>
        </div>
      </section>
      <div class="chapter-strip">
        <span data-i18n="strip.label">Pensato per chi coltiva l’eccellenza</span>
        <div>
          <span data-i18n="strip.business">Imprese &amp; Team</span>
          <i aria-hidden="true">/</i>
          <span>Design</span>
          <i aria-hidden="true">/</i>
          <span>Fashion &amp; Luxury</span>
          <i aria-hidden="true">/</i>
          <span>Hospitality</span>
        </div>
        <a href="#vision" aria-label="La visione" data-i18n-aria="nav.vision"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M12 5v14m-6-6 6 6 6-6"/></svg></a>
      </div>
      <section class="vision section-shell" id="vision" aria-labelledby="vision-title">
        <div class="section-intro">
          <p class="eyebrow" data-i18n="vision.eyebrow">01 — Il vostro capitale di conoscenza</p>
          <p class="side-note" data-i18n="vision.aside">Il valore è nei dettagli. Il sapere che li custodisce, anche.</p>
        </div>
        <div class="vision-content">
          <h2 id="vision-title" tabindex="-1"><span data-i18n="vision.title">Non è solo ciò che sapete.</span><br><em data-i18n="vision.emphasis">È ciò che vi distingue.</em></h2>
          <div class="vision-paragraphs">
            <p data-i18n="vision.body1">L’origine di un materiale. La ragione di una scelta. La soluzione trovata da un collega. Il vostro patrimonio vive in migliaia di documenti, e nella memoria delle persone.</p>
            <p data-i18n="vision.body2">AskMyDocs li mette in relazione. Una domanda, nella propria lingua, diventa una risposta chiara, accompagnata dalle fonti. Perché la conoscenza che vi distingue merita di diventare un vantaggio condiviso.</p>
          </div>
          <div class="principles">
            <div>
              <span class="principle-index">I.</span>
              <h3 data-i18n="principles.0.title">Riunire.</h3>
              <p data-i18n="principles.0.body">Documenti, archivi e strumenti. Finalmente in dialogo.</p>
            </div>
            <div>
              <span class="principle-index">II.</span>
              <h3 data-i18n="principles.1.title">Comprendere.</h3>
              <p data-i18n="principles.1.body">Risposte contestuali, con le fonti sempre a portata di mano.</p>
            </div>
            <div>
              <span class="principle-index">III.</span>
              <h3 data-i18n="principles.2.title">Tramandare.</h3>
              <p data-i18n="principles.2.body">Il sapere giusto, alle persone giuste. Ovunque lavorino.</p>
            </div>
          </div>
        </div>
      </section>
      <section class="experience section-shell" id="experience" aria-labelledby="experience-title">
        <div class="experience-heading">
          <div>
            <p class="eyebrow" data-i18n="experience.eyebrow">02 — Dalla domanda alla conoscenza</p>
            <h2 id="experience-title" tabindex="-1"><span data-i18n="experience.title">Una domanda semplice.</span><br><em data-i18n="experience.emphasis">Un mondo, nella risposta.</em></h2>
          </div>
          <p data-i18n="experience.description">Da una scheda prodotto a una decisione di progetto. Esplora tre esempi e segui il percorso dalla domanda alle fonti.</p>
        </div>
        <div class="experience-stage">
          <div class="archive-visual">
            <img src="{{ $editorialBase }}/archive-night.webp" srcset="{{ $editorialBase }}/archive-night-small.webp 640w, {{ $editorialBase }}/archive-night.webp 1280w" sizes="(max-width: 760px) 100vw, 30vw" width="1280" height="853" loading="lazy" alt="Illustrazione di un archivio di documenti ordinati" data-i18n-alt="images.archive" data-editorial-artwork="archive">
            <span class="archive-caption" data-i18n="experience.photoCaption">Un archivio. Infinite connessioni.</span>
          </div>
          <div class="demo-workspace">
            <div class="demo-topline">
              <span class="demo-brand"><span aria-hidden="true"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M12 3v18M3 12h18M6 6l12 12M6 18 18 6"/></svg></span> AskMyDocs</span>
              <span class="demo-badge" data-i18n="experience.badge">Esempio illustrativo</span>
            </div>
            <div id="scenario-tabs" class="scenario-tabs" role="tablist" aria-label="Esplora un argomento" data-i18n-aria="experience.topics"></div>
            <div id="demo-answer" class="demo-answer" role="tabpanel" tabindex="0" aria-live="polite"></div>
            <div class="demo-bottom">
              <span><span class="status-dot" aria-hidden="true"></span><span data-i18n="experience.bottom">La conoscenza, con le sue fonti.</span></span>
              <a class="text-link" href="/app/me"><span data-i18n="experience.open">Apri l’app</span><span aria-hidden="true"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M7 17 17 7M7 7h10v10"/></svg></span></a>
            </div>
          </div>
        </div>
        <p class="demo-disclaimer" data-i18n="experience.disclaimer">Contenuti dimostrativi inventati per illustrare l’esperienza. Nell’app, le risposte si basano sui documenti del tuo team.</p>
      </section>
      <section class="possibilities section-shell" id="possibilities" aria-labelledby="possibilities-title">
        <div class="possibilities-heading">
          <p class="eyebrow" data-i18n="possibilities.eyebrow">03 — Per chi vuole fare la differenza</p>
          <h2 id="possibilities-title" tabindex="-1"><span data-i18n="possibilities.title">L’intelligenza incontra</span><br><em data-i18n="possibilities.emphasis">il vostro mondo.</em></h2>
        </div>
        <div class="possibilities-grid">
          <figure class="team-visual">
            <img src="{{ $editorialBase }}/community-night.webp" srcset="{{ $editorialBase }}/community-night-small.webp 640w, {{ $editorialBase }}/community-night.webp 1280w" sizes="(max-width: 760px) 100vw, 48vw" width="1280" height="853" loading="lazy" alt="Illustrazione di persone che collaborano attorno a un tavolo" data-i18n-alt="images.team" data-editorial-artwork="community">
            <figcaption>
              <span>03 / 03</span>
              <span data-i18n="possibilities.caption">Persone, idee e conoscenza. Insieme.</span>
            </figcaption>
          </figure>
          <div class="use-cases">
            <article>
              <span class="use-index">01</span>
              <div>
                <h3 data-i18n="useCases.0.title">Ogni dettaglio, una risposta.</h3>
                <p data-i18n="useCases.0.body">Dai materiali alla cura del prodotto, portate la conoscenza vicino al cliente. In boutique, nello showroom o nell’assistenza: precisione e sensibilità, nella stessa conversazione.</p>
                <span class="use-tag" data-i18n="useCases.0.tag">Fashion, Design &amp; Client experience</span>
              </div>
            </article>
            <article>
              <span class="use-index">02</span>
              <div>
                <h3 data-i18n="useCases.1.title">Le idee migliori non partono da zero.</h3>
                <p data-i18n="useCases.1.body">Ritrovate decisioni, ricerche e progetti precedenti. Collegate esperienza tecnica e visione creativa, perché il team possa costruire su ciò che già sa.</p>
                <span class="use-tag" data-i18n="useCases.1.tag">Imprese, Progetti &amp; Innovazione</span>
              </div>
            </article>
            <article>
              <span class="use-index">03</span>
              <div>
                <h3 data-i18n="useCases.2.title">Il sapere segue le persone.</h3>
                <p data-i18n="useCases.2.body">Procedure, standard e formazione accessibili nel momento del bisogno. Dalla sede a una struttura ricettiva, ogni persona può ritrovare il modo giusto di lavorare.</p>
                <span class="use-tag" data-i18n="useCases.2.tag">People, Operations &amp; Hospitality</span>
              </div>
            </article>
          </div>
        </div>
      </section>
      <section class="connections section-shell" aria-labelledby="connections-title">
        <div>
          <p class="eyebrow" data-i18n="connections.eyebrow">In continuità con il vostro mondo</p>
          <h2 id="connections-title" data-i18n="connections.title">Il sapere è già lì. Connettiamolo.</h2>
        </div>
        <div class="connector-list">
          <span><img src="/connectors/google-drive.svg" width="22" height="22" alt="">Google Drive</span>
          <span><img src="/connectors/notion.svg" width="22" height="22" alt="">Notion</span>
          <span><img src="/connectors/onedrive.svg" width="24" height="24" alt="">OneDrive</span>
          <span><img src="/connectors/confluence.svg" width="22" height="22" alt="">Confluence</span>
          <span class="connector-docs"><span aria-hidden="true"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M7 3h8l4 4v14H5V3h2m10 4h-4V3M8 12h8M8 16h8"/></svg></span><span data-i18n="connections.documents">I vostri documenti</span></span>
        </div>
      </section>
      <section class="trust section-shell" id="trust" aria-labelledby="trust-title">
        <div class="trust-intro">
          <p class="eyebrow" data-i18n="trust.eyebrow">04 — La fiducia, per principio</p>
          <h2 id="trust-title" tabindex="-1"><span data-i18n="trust.title">Un patrimonio prezioso.</span><br><em data-i18n="trust.emphasis">Il controllo resta vostro.</em></h2>
          <p data-i18n="trust.description">La conoscenza è un vantaggio distintivo. Scegliete dove custodirla, chi può accedervi e quali modelli AI utilizzare.</p>
          <a class="text-link" href="https://padosoft.mintlify.app/" target="_blank" rel="noopener noreferrer"><span data-i18n="trust.link">Esplora la documentazione</span><span aria-hidden="true"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M7 17 17 7M7 7h10v10"/></svg></span></a>
        </div>
        <div class="trust-details">
          <article>
            <span aria-hidden="true"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="m3 10 9-7 9 7v11H3V10m6 11v-8h6v8"/></svg></span>
            <div>
              <h3 data-i18n="trust.items.0.title">A casa vostra.</h3>
              <p data-i18n="trust.items.0.body">Installazione self-hosted, per mantenere l’infrastruttura sotto il vostro controllo.</p>
            </div>
          </article>
          <article>
            <span aria-hidden="true"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="m12 3 8 4v6c0 4-8 8-8 8s-8-4-8-8V7l8-4Z"/></svg></span>
            <div>
              <h3 data-i18n="trust.items.1.title">A ciascuno, il suo accesso.</h3>
              <p data-i18n="trust.items.1.body">Spazi di lavoro separati e autorizzazioni per rendere disponibile il sapere alle persone abilitate.</p>
            </div>
          </article>
          <article>
            <span aria-hidden="true"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M7 17 17 7M7 7h10v10"/></svg></span>
            <div>
              <h3 data-i18n="trust.items.2.title">Liberi di scegliere.</h3>
              <p data-i18n="trust.items.2.body">Più provider AI e integrazioni MCP. Un ecosistema che può evolvere insieme a voi.</p>
            </div>
          </article>
        </div>
      </section>
      <section class="faq section-shell" id="faq" aria-labelledby="faq-title">
        <div>
          <p class="eyebrow" data-i18n="faq.eyebrow">Per andare oltre</p>
          <h2 id="faq-title" data-i18n="faq.title" tabindex="-1">Qualche risposta, prima di iniziare.</h2>
        </div>
        <div id="faq-list"></div>
      </section>
      <section class="closing section-shell" aria-labelledby="closing-title">
        <p class="eyebrow" data-i18n="closing.eyebrow">Il vostro prossimo capitolo</p>
        <h2 id="closing-title"><span data-i18n="closing.title">L’eccellenza ha una memoria.</span><br><em data-i18n="closing.emphasis">Diamole voce.</em></h2>
        <p data-i18n="closing.description">Scopri cosa succede quando la conoscenza di un’organizzazione diventa il potere di ogni persona.</p>
        <a class="btn btn-primary btn-lg" href="/app/me"><span data-i18n="closing.cta">Entra nella demo</span><span aria-hidden="true"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M7 17 17 7M7 7h10v10"/></svg></span></a>
        <span class="closing-note" data-i18n="closing.note">Accesso con le credenziali del vostro spazio di lavoro.</span>
        <span class="closing-watermark" aria-hidden="true">a.</span>
      </section>
    </main>
    <footer class="footer section-shell">
      <div class="footer-top">
        <a class="brand" href="#top">AskMyDocs<span class="brand-period">.</span></a>
        <p data-i18n="footer.tagline">Il vostro sapere. Una nuova possibilità.</p>
        <div>
          <a href="https://padosoft.mintlify.app/" target="_blank" rel="noopener noreferrer" data-i18n="footer.docs">Documentazione <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M7 17 17 7M7 7h10v10"/></svg></a>
          <a href="https://github.com/lopadova/AskMyDocs" target="_blank" rel="noopener noreferrer">GitHub <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M7 17 17 7M7 7h10v10"/></svg></a>
          <a href="/login" data-i18n="nav.login">Accedi</a>
        </div>
      </div>
      <div class="footer-bottom">
        <span>© <span id="year">2026</span> AskMyDocs</span>
        <span data-i18n="footer.credit">Immagini editoriali create con AI.</span>
        <a href="#top"><span data-i18n="footer.top">Torna all’inizio</span> <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M12 19V5m-6 6 6-6 6 6"/></svg></a>
      </div>
    </footer>
    <dialog id="source-dialog" aria-labelledby="source-title">
      <div class="source-dialog-top">
        <span class="eyebrow" data-i18n="experience.sourceLabel">Fonte dimostrativa</span>
        <button class="btn btn-quiet" id="close-source" data-i18n-aria="close" aria-label="Chiudi"><svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg></button>
      </div>
      <h2 id="source-title"></h2>
      <p class="source-meta" id="source-meta"></p>
      <blockquote id="source-excerpt"></blockquote>
      <p class="source-note" data-i18n="experience.disclaimer">Contenuti dimostrativi inventati per illustrare l’esperienza. Nell’app, le risposte si basano sui documenti del tuo team.</p>
    </dialog>
    <noscript><p class="noscript-note">Attiva JavaScript per cambiare lingua ed esplorare gli esempi. / Enable JavaScript to change language and explore the examples. <a href="/app/me">Demo <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M7 17 17 7M7 7h10v10"/></svg></a></p></noscript>
  </body>
</html>

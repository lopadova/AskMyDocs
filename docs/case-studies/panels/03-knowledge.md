# Gruppo "Knowledge" — pannelli admin SPA

Il gruppo **Knowledge** è il cuore operativo di AskMyDocs: qui si gestisce la
base di conoscenza tenant-scoped da cui il RAG estrae le risposte. Raccoglie
otto voci di navigazione (la **Knowledge Base** centrale più sette pannelli di
contorno: Collections, Synonyms, Doc Insights, Analysis Gate, Content Gaps,
Tabular Reviews, Workflows) e due sotto-pagine della Knowledge Base raggiungibili
solo da contesto (KB Health e Time Machine). La voce di navigazione è la fonte di
verità in `frontend/src/components/shell/nav-config.ts` (gruppo `knowledge`); le
route e i ruoli RBAC vivono in `frontend/src/routes/index.tsx`.

Tutti i pannelli sono **tenant-scoped** e quasi tutti **per-progetto**: ogni
query su `knowledge_documents` e sulle tabelle del grafo passa per
`forTenant($ctx->current())` (R30) e per lo `where('project_key', …)`. Questo è
il pilastro dei check di isolamento descritti in ogni sezione: i tre dataset
fittizi del case study — `rotta-logistics` (logistica/spedizioni),
`prometeo-antincendio` (consulenza normativa antincendio/vigili del fuoco),
`passolibero-calzature` (vendita scarpe) — non devono mai contaminarsi a vicenda.

> Convenzione di lettura: dove scrivo "Ruoli", intendo l'allow-list della
> `RequireRole` che avvolge la route nel componente. La difesa autorevole resta
> comunque server-side (Gate Spatie + `tenant.authorize`); la guardia FE serve a
> mostrare `<AdminForbidden />` invece di scatenare una raffica di 403.

---

## Knowledge Base (`/app/admin/kb`)

- **Percorso**: route SPA `/app/admin/kb` (deep-link supportato con `?doc=<id>&tab=<preview|source|meta|history|graph>`); gruppo sidebar **Knowledge** → *Knowledge Base*. Componente: `frontend/src/features/admin/kb/KbView.tsx`.
- **Ruoli**: `admin`, `super-admin` (`RequireRole roles={['admin','super-admin']}` su `AdminKbRoute`).
- **A cosa serve**: è l'explorer della base di conoscenza. Permette di navigare l'albero dei documenti markdown (canonici e raw) per progetto, ispezionarne il contenuto e i metadati canonici, modificarli inline, vederne la storia editoriale e il grafo delle relazioni, e gestire eliminazione/ripristino. È il punto in cui un operatore vede esattamente cosa il RAG userà come grounding.
- **Cosa vedi nella pagina**:
  - Header con titolo **"Knowledge Base"** e un **project picker** (`kb-project-select`): un `<select>` con `All projects` + un'opzione per ogni `project_key` reale derivato dal DB (R18 — non più hard-coded `hr-portal`/`engineering`).
  - **Pannello albero a sinistra** (`kb-tree`, `data-state=loading|ready|error|empty`): barra filtri con ricerca client-side (`kb-tree-q`), selettore modalità (`kb-tree-mode`: *All documents* / *Canonical only* / *Raw only*), checkbox **Include deleted** (`kb-tree-with-trashed`) e un contatore (`kb-tree-counts`, es. `11 docs · 4 canonical`). L'albero è un `<ul role="tree">` con cartelle espandibili e foglie-documento; le foglie canoniche portano un badge col `canonical_type`, le trashed sono barrate.
  - **Pannello dettaglio a destra** (`kb-detail-pane`): se nessun doc è selezionato mostra un placeholder; altrimenti il `DocumentDetail` con header (path, titolo, pill `canonical_type`/`canonical_status`/`trashed`) e una barra azioni: **Download**, **Print**, **Export PDF**, **Delete** (soft) / **Restore** (se trashed), **Force delete**. Le due delete aprono un dialog di conferma (`kb-detail-confirm`).
  - **Tab strip** (`kb-tabs`, `role=tablist`): **Preview**, **Source**, **Meta**, **History**, **Graph** (dettaglio sotto).
- **Tab in dettaglio**:
  - **Preview** (`PreviewTab`): rende il markdown via il renderer interno; in cima un pill-pack del frontmatter (`frontmatter-pills`). Stati: `kb-preview-loading` / `kb-preview-error` / `kb-preview`.
  - **Source** (`SourceTab`): editor **CodeMirror 6** inline (`kb-editor-cm`) con toolbar **Save / Cancel / Show diff** (`kb-editor-save` ecc.) e indicatore *unsaved changes / in sync with disk*. Il Save fa `PATCH …/raw`, registra un audit `updated` e accoda `IngestDocumentJob` (re-ingest). Errori 422 sul frontmatter compaiono per-campo in `kb-editor-error-frontmatter`; errori generici in `kb-editor-error`.
  - **Meta** (`MetaTab`): tabella sola lettura di ogni colonna canonica — Project, Slug, Doc ID, Canonical type/status, Is canonical, Retrieval priority, Source of truth, Indexed at, Deleted at, Chunks, Audit events — più i tag (`kb-meta-tags`) e un blocco di suggerimenti AI per il doc (`kb-meta-ai-suggestions`).
  - **History** (`HistoryTab`): elenco paginato delle righe di `kb_canonical_audit` (event_type + actor + timestamp, con diff `before/after` espandibile). Pager `kb-history-prev` / `kb-history-next`.
  - **Graph** (`GraphTab`): subgrafo radiale a 1 hop in SVG (`kb-graph`, `data-state=loading|ready|error|empty`). Il nodo centrale è il doc; i nodi-vicini sono collegati via `kb_edges`. Su doc raw senza nodo canonico ritorna `empty` (200 + nodi vuoti) con messaggio "canonicalize the document…".
- **Dati / endpoint** (tutti `auth:sanctum` + tenant + `can:`):
  - `GET /api/admin/kb/tree?project=&mode=&with_trashed=` → `KbTreeController@index` → `KbTreeService::build()` (chunkById(100), scopes `canonical()`/`raw()`, soft-delete aware).
  - `GET /api/admin/kb/projects` → `KbTreeController@projects` (lista distinct `project_key`).
  - `GET /api/admin/kb/documents/{id}` → `KbDocumentController@show` (binding `withTrashed()`).
  - `GET …/{id}/raw`, `PATCH …/{id}/raw`, `GET …/{id}/download`, `GET …/{id}/print`, `POST …/{id}/restore`, `GET …/{id}/history`, `GET …/{id}/graph`, `POST …/{id}/export-pdf`, `DELETE …/documents/{id}?force=` → `KbDocumentController`.
  - Servizio chiave: `app/Services/Admin/KbTreeService.php`; controller: `app/Http/Controllers/Api/Admin/KbDocumentController.php`, `KbTreeController.php`.
- **Come testarlo con i 3 dataset**:
  1. Dopo l'ingest dei tre dataset, apri `/app/admin/kb` con il picker su **All projects**: `kb-tree-counts` deve riportare il totale aggregato dei tre progetti.
  2. Seleziona **rotta-logistics** dal picker: l'albero deve mostrare **solo i ~11 documenti di logistica** (spedizioni, tracking, vettori, ecc.) — nessun documento di Prometeo o Passolibero. Il contatore deve scendere a ~11 docs.
  3. **Check di isolamento**: passa a **prometeo-antincendio** → spariscono i doc di logistica e compaiono solo i documenti normativi antincendio; idem passando a **passolibero-calzature** (catalogo/taglie/resi). In nessuna combinazione un documento di un'azienda deve apparire sotto un'altra.
  4. Apri un documento canonico di rotta-logistics e vai sul tab **Graph**: i nodi-vicini e le edge devono collegare **solo nodi della stessa azienda** (es. un `decision` logistico collegato a un `runbook` logistico). Non devono comparire nodi di Prometeo/Passolibero — i comporti FK su `kb_edges` rendono gli edge cross-tenant strutturalmente impossibili.
  5. Sul tab **Source** modifica una riga e premi **Save**: l'indicatore passa a *unsaved changes* → dopo il save compare il toast "Saved — re-ingest queued" e il tab **History** acquisisce una nuova riga `updated` con actor il tuo utente.
  6. Premi **Delete** su un doc raw → conferma soft delete; attiva **Include deleted** per rivederlo barrato, poi **Restore**. Verifica che il contatore `· N trashed` segua il ciclo.

### Sotto-pagina — KB Health (`/app/admin/kb/health`)

- **Percorso**: route SPA `/app/admin/kb/health`; **non** ha una voce propria nella sidebar (la sezione attiva resta *Knowledge Base*, `AdminShell section="kb"`). Componente: `frontend/src/features/admin/kb-health/KbHealthView.tsx`.
- **Ruoli**: `admin`, `super-admin` (`AdminKbHealthRoute`).
- **A cosa serve**: una heatmap del "debito decisionale" per documento canonico. Più alto è lo score, più il documento accumula decisioni superate / incidenti / approcci rifiutati che andrebbero ripuliti. Serve all'editor per capire dove la KB è più "tossica".
- **Cosa vedi nella pagina**: titolo **"KB health heatmap"**; filtri `project_key` (input testo) e **Min score**; un riquadro con `threshold_event_score`; una riga di aggregati (Total / Avg / Max); una griglia 10-colonne di celle colorate (rosso più intenso = score più alto), ognuna con `project_key`, slug e score. Stati `Loading…` / errore.
- **Dati / endpoint**: `GET /api/admin/kb/health?project=&min_score=&limit=` → `KbHealthController@index`.
- **Come testarlo con i 3 dataset**: lascia il filtro progetto vuoto per vedere le celle di tutti e tre i dataset; poi digita `rotta-logistics` nell'input `project_key` → la griglia deve restringersi ai soli documenti logistici. **Check di isolamento**: i tooltip e gli slug delle celle devono riportare solo `rotta-logistics/...` quando filtri su quel progetto, mai slug di Prometeo o Passolibero. Alza **Min score** per isolare i documenti con più debito.

### Sotto-pagina — Time Machine (`/app/admin/kb/time-machine/$docId`)

- **Percorso**: route SPA `/app/admin/kb/time-machine/<docId>` (parametro path); raggiungibile per documento, non c'è voce sidebar (sezione attiva `time-machine`). Componente: `frontend/src/features/admin/time-machine/TimeMachineView.tsx`.
- **Ruoli**: `admin`, `super-admin` (`AdminKbTimeMachineRoute`). Un `docId` non numerico/positivo mostra `kb-time-machine-invalid`.
- **A cosa serve**: la timeline delle versioni di un singolo documento. Ogni re-ingest archivia la versione precedente; qui si scelgono due versioni per fare il **diff** e si **ripristina** una versione archiviata rendendola di nuovo *live*.
- **Cosa vedi nella pagina**: header **"Time Machine"** con sottotitolo `project_key · source_path · N versions` (`kb-time-machine-source`); elenco righe-versione (`kb-time-machine-version-<id>`) con hash, titolo, badge *live*, e i pulsanti **From** / **To** / **Restore** (Restore assente sulla versione live); sezione **Diff** (`kb-time-machine-diff`) con riepilogo `+aggiunte / −rimozioni` e corpo riga-per-riga colorato. Stati distinti loading/empty/error e un `kb-time-machine-restore-error` (`role=alert`).
- **Dati / endpoint**: `GET /api/admin/kb/documents/{id}/versions` → `KbDocumentVersionController@index`; `…/versions/diff?from=&to=` → `@diff`; `POST …/{id}/restore-version` → `@restore`.
- **Come testarlo con i 3 dataset**: prendi un documento di rotta-logistics e fai due ingest successivi con contenuto diverso (es. modifica un paragrafo del runbook spedizioni). Apri la sua Time Machine: devi vedere ≥2 versioni; seleziona **From** sulla vecchia e **To** sulla nuova → il diff mostra solo le tue modifiche. **Check di isolamento**: l'header `project_key` deve riportare `rotta-logistics`; ripetendo l'operazione su un doc di Passolibero il `source_path` e le versioni devono essere quelle del catalogo scarpe, mai mescolate con logistica/antincendio. Premi **Restore** su una versione archiviata e verifica che diventi *live*.

---

## Collections (`/app/admin/collections`)

- **Percorso**: route SPA `/app/admin/collections`; gruppo sidebar **Knowledge** → *Collections* (`AdminShell section="collections"`). Componente: `frontend/src/features/admin/collections/CollectionsView.tsx`.
- **Ruoli**: `admin`, `super-admin` (`AdminCollectionsRoute`).
- **A cosa serve**: definisce raccolte di documenti — statiche (membri aggiunti a mano) o semantiche (un *prompt* + soglia di similarità) — per raggruppare/scopare la conoscenza. Una collection può poi restringere il retrieval o servire come vista curata su un sottoinsieme della KB.
- **Cosa vedi nella pagina**: layout a due colonne. A sinistra la **lista collection** (`admin-collections-list`) con un bottone per riga. A destra l'**editor** (`admin-collections-editor`): campi Name, slug, Description, **Semantic prompt** (opzionale), **Threshold** (0–1); un contatore di **anteprima** (`admin-collections-preview-count`, "Would include N document(s)") che si aggiorna live al variare di criteri/prompt/threshold; pulsanti **Create / Save / Delete**. In basso la gestione **Members**: input per `Knowledge document id`, **Add member**, ed elenco membri con motivo (`[reason]`, eventuale `(excluded)`) e **Remove**.
- **Dati / endpoint**: `GET/POST/PUT/DELETE /api/admin/kb/collections` (apiResource → `KbCollectionController`); `POST …/collections/preview` (anteprima conteggio); `GET/POST/DELETE …/collections/{id}/members[/{documentId}]`.
- **Come testarlo con i 3 dataset**: crea una collection "Spedizioni urgenti" con un semantic prompt sul tema logistico e threshold alto; aggiungi come membro statico l'`id` di un documento di rotta-logistics e verifica il `[reason]` in elenco. **Attenzione — le collection NON hanno una dimensione progetto**: sono per-tenant (`uq_kb_collections_tenant_slug`), i criteri semantici valutano l'intera KB del tenant e i tre dataset convivono nello stesso tenant `default`. È quindi **atteso** che l'anteprima di un prompt sul tema scarpe conti anche documenti PassoLibero: non è una violazione di isolamento. L'isolamento per-PROGETTO non si verifica qui (usare i test chat del README §6); ciò che deve valere è il confine **tenant**: una collection creata in un altro tenant non deve mai essere visibile né "pescare" documenti di questo.

---

## Synonyms (`/app/admin/kb/synonyms`)

- **Percorso**: route SPA `/app/admin/kb/synonyms`; gruppo sidebar **Knowledge** → *Synonyms* (`AdminShell section="synonyms"`). Componente: `frontend/src/features/admin/synonyms/SynonymsList.tsx`.
- **Ruoli**: `admin`, `super-admin` (`AdminSynonymsRoute`).
- **A cosa serve**: gestisce i gruppi di sinonimi per-(tenant, progetto). A retrieval-time una query che contiene un membro del gruppo cerca anche tutti gli altri membri, così gergo di settore, acronimi e codici interni si collegano ai loro equivalenti in lingua naturale.
- **Cosa vedi nella pagina**: header **"Synonyms"** con contatore `N total`, filtro testuale (`admin-synonyms-filter` — per progetto/termine/sinonimo) e bottone **+ New group**. Una tabella (`admin-synonyms-table`) con colonne **Project / Term / Synonyms / Enabled / Actions**; ogni riga ha **Edit** e **Delete** (con conferma inline Confirm/Cancel sulla riga). Stati distinti loading / error (`role=alert`) / empty / no-match. Create ed Edit aprono un `SynonymFormDialog`.
- **Dati / endpoint**: `GET/POST/PUT/DELETE /api/admin/kb/synonyms` (apiResource → `SynonymController`).
- **Come testarlo con i 3 dataset**: crea per `rotta-logistics` un gruppo `term=POD` con sinonimi `prova di consegna, proof of delivery`; per `prometeo-antincendio` un gruppo `term=CPI` con `certificato prevenzione incendi`; per `passolibero-calzature` un gruppo `term=EU38` con `taglia 38`. **Check di isolamento**: filtrando per `rotta-logistics` la tabella deve mostrare solo il gruppo POD; il gruppo CPI/EU38 non deve comparire sotto il progetto sbagliato. Disabilita un gruppo (Enabled → Off) e verifica che resti scoped al suo progetto.

---

## Doc Insights (`/app/admin/kb/insights`)

- **Percorso**: route SPA `/app/admin/kb/insights`; gruppo sidebar **Knowledge** → *Doc Insights* (`AdminShell section="kb-insights"`). Componente: `frontend/src/features/admin/kb-insights/KbInsightsView.tsx`.
- **Ruoli**: `admin`, `super-admin` (`AdminKbInsightsRoute`).
- **A cosa serve**: elenco **sola lettura** delle analisi AI generate quando un documento canonico viene ingerito, modificato o eliminato. Ogni analisi suggerisce come rafforzare il documento, segnala cross-reference e indica quali altri doc la modifica potrebbe aver reso obsoleti (o, per una delete, quali doc restano con riferimenti pendenti). È advisory — non muta nulla.
- **Cosa vedi nella pagina**: header **"Doc Insights"** con contatore `N total` e filtro **Status** (`All / Completed / Failed`). Una lista di card (`admin-kb-insight-<id>`): titolo doc, `project_key`, trigger, status; sezioni **Suggestions**, **Impacted docs** (con `→ suggested_action`), **Cross-references**; le analisi fallite mostrano un blocco errore. Stati distinti loading / error / empty.
- **Dati / endpoint**: `GET /api/admin/kb/analyses?status=` → `KbDocAnalysisController@index`.
- **Come testarlo con i 3 dataset**: dopo l'ingest dei tre dataset, attendi che gli `analyses` AI vengano calcolati. **Prerequisito**: le analisi richiedono le chiavi del provider **chat** (oltre agli embeddings) e `KB_CHANGE_ANALYSIS_ENABLED` attivo — senza chat key le card restano vuote/Failed (vedi nota costi nel README §3). Le card devono comparire con il `project_key` corretto. **Check di isolamento**: usando il filtro/leggendo le card, le analisi di un documento di rotta-logistics devono citare come *impacted docs* e *cross-references* solo documenti logistici — mai documenti normativi antincendio o di catalogo scarpe. Filtra **Failed** per isolare eventuali analisi non riuscite.

---

## Analysis Gate (`/app/admin/kb/analysis-settings`)

- **Percorso**: route SPA `/app/admin/kb/analysis-settings`; gruppo sidebar **Knowledge** → *Analysis Gate* (`AdminShell section="analysis-settings"`). Componente: `frontend/src/features/admin/analysis-settings/AnalysisSettingsView.tsx`.
- **Ruoli**: `admin`, `super-admin` (`AdminAnalysisSettingsRoute`).
- **A cosa serve**: gate per-(tenant, progetto) della deep-analysis AI descritta in Doc Insights. Permette di accendere/spegnere l'analisi separatamente per il percorso di modifica, per lo split canonical / non-canonical e per il percorso on-delete, con ereditarietà a cascata (progetto → tenant `*` → default di config).
- **Cosa vedi nella pagina**: header **"Deep-Analysis Gate"**; una riga **All projects (tenant default)** più una riga per ogni progetto. Ogni riga espone quattro controlli tri-stato **Inherit / On / Off** (`enabled`, `canonical`, `non_canonical`, `delete_enabled`), e accanto a ciascuno il valore **effettivo risolto** (`→ on/off`). Errori di salvataggio in `admin-analysis-settings-save-error` (`role=alert`). Stati loading / error / empty.
- **Dati / endpoint**: `GET /api/admin/kb/analysis-settings` → `KbAnalysisSettingController@index`; `PUT /api/admin/kb/analysis-settings` → `@upsert`.
- **Come testarlo con i 3 dataset**: dopo l'ingest, le righe per-progetto devono includere `rotta-logistics`, `prometeo-antincendio`, `passolibero-calzature`. Imposta su `rotta-logistics` il flag `delete_enabled = Off` lasciando `Inherit` altrove: il valore effettivo accanto deve passare a `→ off` solo su quella riga. **Check di isolamento**: la modifica su rotta-logistics non deve cambiare il valore effettivo mostrato per gli altri due progetti (che restano sull'ereditarietà dalla riga tenant-default).

---

## Content Gaps (`/app/admin/kb/content-gaps`)

- **Percorso**: route SPA `/app/admin/kb/content-gaps`; gruppo sidebar **Knowledge** → *Content Gaps* (`AdminShell section="content-gaps"`). Componente: `frontend/src/features/admin/content-gaps/ContentGapsView.tsx`.
- **Ruoli**: `admin`, `super-admin` (`AdminContentGapsRoute`).
- **A cosa serve**: le domande a cui la KB **non ha saputo rispondere**, classificate per quante volte sono state poste. Ogni volta che l'assistente rifiuta (nessun contesto fondato, oppure il modello declina) la domanda viene registrata qui. Gli editor la usano per decidere cosa scrivere e poi marcare il gap come risolto.
- **Cosa vedi nella pagina**: header **"Content Gaps"** con contatore `N total`, filtro **Reason** (opzioni derivate da `available_reasons` della risposta API, non hard-coded — R18) e checkbox **Show resolved**. Una lista (`admin-content-gaps-list`) di righe ordinate per occorrenze: conteggio `N×`, testo query, `project_key`, label del motivo, e un bottone **Resolve** (o badge `resolved`). Errori di azione in `admin-content-gaps-action-error`. Stati loading / error / empty.
- **Dati / endpoint**: `GET /api/admin/kb/content-gaps?reason=&includeResolved=` → `KbContentGapController@index`; `PATCH /api/admin/kb/content-gaps/{id}/resolve` → `@resolve`.
- **Come testarlo con i 3 dataset**: poni in chat domande che la KB non copre per ciascun tenant (es. per rotta-logistics "tariffe doganali extra-UE 2030?", per prometeo "norma su estintori subacquei?", per passolibero "scarpe taglia 60?"). Tornando in Content Gaps quelle domande devono comparire con `project_key` corretto e occorrenze crescenti se ripetute. **Check di isolamento**: la riga di un gap deve riportare il `project_key` del tenant che ha posto la domanda; un gap di logistica non deve apparire quando si lavora nel contesto di Passolibero. Premi **Resolve** e verifica che con **Show resolved** off scompaia.

---

## Tabular Reviews (`/app/admin/tabular-reviews`)

- **Percorso**: route SPA `/app/admin/tabular-reviews`; gruppo sidebar **Knowledge** → *Tabular Reviews* (`AdminShell section="tabular-reviews"`). Componente: `frontend/src/features/admin/tabular-reviews/TabularReviewsList.tsx`.
- **Ruoli**: `admin`, `super-admin`, `viewer` (`AdminTabularReviewsRoute` — il `viewer` ha accesso **sola lettura**; le mutazioni sono bloccate server-side da `denyMutationForViewer()` e i bottoni di scrittura sono nascosti se non sei admin/super-admin).
- **A cosa serve**: trasforma un insieme di documenti in una **tabella estratta dall'AI**. Definisci colonne (nome + prompt di estrazione + formato), poi generi le celle: per ogni documento l'AI estrae il valore richiesto da ciascuna colonna. Serve a confrontare a colpo d'occhio molti documenti su criteri omogenei.
- **Cosa vedi nella pagina**: header **"Tabular Reviews"** con **+ New review** (solo se puoi mutare). Tabella catalogo con **Title / Project / Columns / Updated / Actions** (Delete per riga). Cliccando un titolo si apre la **show page** (`admin-tabular-review-show`): header con progetto, bottoni **Generate cells** / **Clear cells**, e una griglia documenti×colonne con celle colorate per flag (verde ✓ / giallo ⚠ / rosso ✗ / grigio ○) + reasoning. Il dialog **New Tabular Review** ha Title, Project key, e un builder di colonne (Name / Extraction prompt / Format da `FORMAT_TYPES`). Stati distinti loading / error / empty.
- **Dati / endpoint** (prefix `admin/tabular-reviews`): `GET/POST .../tabular-reviews`, `GET/PUT/DELETE .../{id}`, `POST .../{id}/generate`, `POST .../{id}/clear-cells`, `POST .../{id}/regenerate-cell` → `TabularReviewController` (più lo stream SSE in `TabularReviewStreamController`, non usato dalla show page GA).
- **Come testarlo con i 3 dataset**: crea una review **Project key = rotta-logistics** con colonne tipo "Vettore", "Tempo di transito", "Zona". Premi **Generate cells**: le righe devono essere i documenti di rotta-logistics e i valori estratti devono provenire solo da quei documenti. **Check di isolamento**: una review con `project_key=prometeo-antincendio` deve popolare la griglia con documenti normativi antincendio, mai con il catalogo scarpe; cambiare progetto cambia integralmente il set di righe. Verifica che da `viewer` i bottoni Create/Delete/Generate **non** siano visibili.

---

## Workflows (`/app/admin/workflows`)

- **Percorso**: route SPA `/app/admin/workflows`; gruppo sidebar **Knowledge** → *Workflows* (`AdminShell section="workflows"`). Componente: `frontend/src/features/admin/workflows/WorkflowsList.tsx`.
- **Ruoli**: `admin`, `super-admin`, `viewer` (`AdminWorkflowsRoute` — il `viewer` vede in lettura e può **Hide** un workflow dal proprio catalogo; Create e "Get suggestions" sono riservati ad admin/super-admin via `denyMutationForViewer()` / `assertCanSuggest()`).
- **A cosa serve**: catalogo di workflow riusabili (prompt/assistant salvati, eventualmente tabulari) che incapsulano un compito ricorrente sulla KB. Tre scope: **Mine**, **Shared with me**, **System**. Un suggeritore AI propone workflow a partire dai dati del tenant, e ogni proposta è salvabile con un click.
- **Cosa vedi nella pagina**: header **"Workflows"** con **+ New workflow** e **Get suggestions from my data** (entrambi solo se puoi mutare). Tab di scope (`Mine / Shared with me / System`). Griglia di card workflow (`admin-workflow-card-<id>`) con titolo, badge tipo (`assistant`/`tabular`), eventuale `practice`, e un bottone **Hide**. Il dialog **New workflow** ha Title, Type (Assistant; Tabular disabilitato in GA), Prompt markdown, Practice. La **SuggestionsGallery** (dialog) elenca le proposte AI con **Save this**. Stati loading / error / empty + errori di mutazione (`role=alert`).
- **Dati / endpoint** (prefix `admin/workflows`): `GET/POST .../workflows`, `GET/PUT/DELETE .../{id}`, `POST .../suggest`, `POST .../from-proposal`, `POST .../{id}/share|unshare|hide|unhide` → `WorkflowController`.
- **Come testarlo con i 3 dataset**: da admin premi **Get suggestions from my data** e salva una proposta con **Save this** → compare nello scope **Mine**. **Attenzione — il suggeritore è tenant-wide**: `POST .../suggest` legge i dati dell'INTERO tenant senza dimensione progetto, quindi con i 3 dataset caricati è **atteso** che le proposte spazino su logistica, antincendio e calzature insieme (es. "Estrai SLA per vettore" accanto a "Estrai classi estintori"): non è un difetto di isolamento. L'isolamento per-PROGETTO non si verifica qui (usare i test chat del README §6); il confine da verificare è il **tenant**: un workflow salvato in un tenant non deve apparire nello scope di un altro tenant. Verifica che da `viewer` solo il bottone **Hide** sia presente, non Create/Suggest.

---

## Riepilogo isolamento per-progetto

| Pannello | Per-progetto | Punto di verifica isolamento |
|---|---|---|
| Knowledge Base | sì | Albero filtrato per progetto + grafo con soli nodi della stessa azienda |
| KB Health | sì | Celle/slug del solo progetto filtrato |
| Time Machine | sì (per doc) | Header `project_key`/`source_path` del doc |
| Collections | no — solo tenant | Nessuna dimensione progetto: l'anteprima semantica può includere tutti i progetti del tenant (atteso); il confine è il tenant |
| Synonyms | sì | Gruppi visibili solo sotto il proprio progetto |
| Doc Insights | sì | Impacted/cross-ref solo documenti stesso progetto |
| Analysis Gate | sì | Override su un progetto non cambia gli effettivi degli altri |
| Content Gaps | sì | `project_key` del tenant che ha posto la domanda |
| Tabular Reviews | sì | Righe = soli documenti del `project_key` della review |
| Workflows | no — solo tenant | Suggeritore tenant-wide: proposte multi-azienda sono attese; il confine è il tenant |

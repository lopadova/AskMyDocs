# AskMyDocs — Case Study & Guida ai Test

Questo pacchetto serve a **due cose**:

1. **Documentare la SPA admin pannello per pannello** — cosa serve ogni voce
   della sidebar e come si collauda. → cartella [`panels/`](panels/).
2. **Collaudare l'isolamento multi-azienda** — caricare i documenti di **tre
   aziende di settori completamente diversi** in **tre progetti separati** e
   verificare che, quando si interroga un'azienda, **i documenti delle altre
   non vengano mai mischiati** nelle risposte. → cartella [`data/`](data/) +
   [`ingest.sh`](ingest.sh).

L'ambiente di sviluppo resta **uno solo**: la separazione tra le aziende è
logica (per `project_key`), non per istanza.

---

## 1. Le tre aziende

| # | Azienda | `project_key` | Settore | Documenti |
|---|---|---|---|---|
| A | **Rotta Sicura Logistics** | `rotta-logistics` | Logistica e spedizioni | 11 → [`data/rotta-logistics/`](data/rotta-logistics/) |
| B | **Prometeo Sicurezza Antincendio** | `prometeo-antincendio` | Normativa antincendio / Vigili del Fuoco | 11 → [`data/prometeo-antincendio/`](data/prometeo-antincendio/) |
| C | **PassoLibero Calzature** | `passolibero-calzature` | Vendita scarpe (e-commerce) | 11 → [`data/passolibero-calzature/`](data/passolibero-calzature/) |

Ogni dataset è in **italiano**, con **frontmatter canonico** (così esercita
anche il grafo, i tipi canonici, gli "approcci rifiutati") e contiene dei
**fatti-esca unici** (codici, nomi propri, numeri) che *non devono mai
comparire* nelle risposte di un'altra azienda. Sono il rilevatore di
contaminazione del test (vedi §6).

### Fatti-esca per azienda (i "canarini" del test)

| Azienda | Esche uniche (esempi) |
|---|---|
| **A — Rotta** | hub `HUB-MI-07` / `HUB-NA-03` / `HUB-RM-05`; servizio *"Consegna Lampo 24h"*; cut-off ordini **17:30**; corrieri *VeloxCorriere* / *TurboPony*; WMS *OrbitaWMS*; prefisso tracking **`RL-`**; SLA 98%/24h, penale 2%/giorno; verde **800-ROTTA1** |
| **B — Prometeo** | **CPI** rinnovo **5 anni**, **D.M. 03/08/2015 art. 14**; *Protocollo Fenice-7*; *Corso Salamandra* (4/8/16 h); *Modulo PA-12*; estintore **34A 233BC**; **UNI EN ISO 7010**; software *ScadenzarioPRO*; cliente *Cantiere Aurora* |
| **C — PassoLibero** | *Zefiro Run 2.0* (**PL-ZR20**, €129,90), *Aria City* (PL-AC11), *Sentiero Trek* (PL-ST33); materiale *AeroMesh* / suola *GripFlex*; resi **30 giorni**; fedeltà *ClubPasso*; corriere *BrioExpress*, gratis sopra **€60**; richiamo lotto **ZR20-LOT07**; verde **800-PASSO9** |

Alcune esche sono volutamente su **temi sovrapposti** (sia Rotta sia
PassoLibero parlano di *spedizioni* e *resi*) ma con **valori diversi**: serve
a testare che il sistema non confonda due documenti semanticamente simili di
aziende diverse (vedi §6.3).

---

## 2. Come l'isolamento è garantito (dove guardare nel codice)

La separazione poggia su **due assi**, entrambi applicati nel percorso di
retrieval:

1. **`tenant_id`** — tutte le query KB passano da `forTenant($tenantId)`
   (trait `BelongsToTenant`). In dev il tenant è `default`.
   → `app/Services/Kb/KbSearchService.php`, regola **R30**.
2. **`project_key`** — la ricerca filtra `where('knowledge_chunks.project_key', …)`
   sul progetto richiesto dalla chat.

In una chat, **lo scope di progetto del singolo turno lo decide la barra
filtri** (`filters.project_keys`), **non** il selettore progetto del topbar:
in `KbChatRequest::toFilters()` il payload `filters.project_keys` ha **sempre
la precedenza** sul `project_key` legacy. Per i test usa quindi il **filtro
progetto nella chat** (o l'API, §6.4).

Quando il contesto recuperato è insufficiente, scatta un **rifiuto
deterministico** (nessuna invenzione): `refusal_reason: "no_relevant_context"`
(senza nemmeno chiamare l'LLM) oppure `llm_self_refusal`. È esattamente il
comportamento corretto quando chiedo all'azienda A un fatto che vive solo in B.

---

## 3. Setup — caricare i tre dataset (un comando)

**Prerequisiti**: DB migrato e raggiungibile; chiavi per gli **embeddings**
configurate (`AI_EMBEDDINGS_PROVIDER` + relativa API key — l'ingest calcola gli
embedding dei chunk); comandi lanciati dalla **root del progetto**.

```bash
cd /path/to/AskMyDocs   # la root del repository
docs/case-studies/ingest.sh
```

Lo script [`ingest.sh`](ingest.sh), per ciascuna azienda:

1. copia `data/<key>/*.md` sul **disco `kb`** in `<root>/<KB_PATH_PREFIX>/case-studies/<key>/`
   (root e prefix risolti dalla config, non hard-coded; il disco `kb` è la
   sorgente da cui legge l'ingestione, e lo script richiede un disco `local`);
2. lancia `php artisan kb:ingest-folder case-studies/<key> --project=<key> --recursive --sync`;
3. concede a **tutti gli utenti** la `ProjectMembership` sui tre progetti (così
   compaiono nel Project Switcher);
4. ricostruisce il **grafo canonico** (`kb:rebuild-graph`) e drena la coda.

> È **idempotente**: rilanciarlo non duplica nulla (l'ingest fa upsert su hash).

### Verifica rapida dopo l'ingest

```bash
# 11 documenti per progetto, tenant 'default'
php artisan tinker --execute='
  foreach (["rotta-logistics","prometeo-antincendio","passolibero-calzature"] as $k) {
    $n = \App\Models\KnowledgeDocument::where("project_key",$k)->count();
    echo "$k: $n documenti\n";
  }'
```

Atteso: `11` per ciascuno. In alternativa, da SPA: **Knowledge Base**
(`/app/admin/kb`) → scegli il progetto nel picker → l'albero mostra **solo** i
documenti di quell'azienda.

### Login di prova (seeder demo)

Se hai girato `DemoSeeder`/`RbacSeeder`: `admin@demo.local` / `password`
(ruolo *admin*, vede quasi tutti i pannelli), `super@demo.local` / `password`
(*super-admin*, vede tutto), `viewer@demo.local` / `password` (*viewer*).

---

## 4. Mappa dei pannelli della sidebar (24 voci, 5 gruppi)

Sorgente di verità: `frontend/src/components/shell/nav-config.ts` (gruppi/voci)
e `frontend/src/routes/index.tsx` (route + ruoli). Documentazione di dettaglio,
con "a cosa serve / cosa vedi / come testarlo", nei file linkati.

### Workspace — [`panels/01-workspace-chat.md`](panels/01-workspace-chat.md)
| Pannello | Route | Ruoli |
|---|---|---|
| **Chat** | `/app/chat`, `/app/chat/$id` | qualsiasi utente autenticato |
| Chat anonima | `/app/chat/anonymous` | autenticato (flag `KB_ANONYMOUS_CHAT_ENABLED`) |

### Administration — [`panels/02-administration.md`](panels/02-administration.md)
| Pannello | Route | Ruoli |
|---|---|---|
| **Dashboard** | `/app/admin` | admin, super-admin |
| **AI Insights** | `/app/admin/insights` | admin, super-admin |
| **Users** | `/app/admin/users` | admin, super-admin |
| **Roles** | `/app/admin/roles` | admin, super-admin |

### Knowledge — [`panels/03-knowledge.md`](panels/03-knowledge.md)
| Pannello | Route | Ruoli |
|---|---|---|
| **Knowledge Base** | `/app/admin/kb` | admin, super-admin |
| ↳ KB Health | `/app/admin/kb/health` | admin, super-admin |
| ↳ Time Machine | `/app/admin/kb/time-machine/$docId` | admin, super-admin |
| **Collections** | `/app/admin/collections` | admin, super-admin |
| **Synonyms** | `/app/admin/kb/synonyms` | admin, super-admin |
| **Doc Insights** | `/app/admin/kb/insights` | admin, super-admin |
| **Analysis Gate** | `/app/admin/kb/analysis-settings` | admin, super-admin |
| **Content Gaps** | `/app/admin/kb/content-gaps` | admin, super-admin |
| **Tabular Reviews** | `/app/admin/tabular-reviews` | admin, super-admin, viewer (R/O) |
| **Workflows** | `/app/admin/workflows` | admin, super-admin, viewer (R/O) |

### Compliance — [`panels/04-compliance.md`](panels/04-compliance.md)
| Pannello | Route | Ruoli |
|---|---|---|
| **AI Act** | `/app/admin/ai-act-compliance` | admin, super-admin, dpo |
| **Compliance Reports** | `/app/admin/compliance/reports` | admin, super-admin |
| **PII Redactor** | `/app/admin/pii-redactor` | admin, super-admin, dpo (flag `PII_REDACTOR_ADMIN_ENABLED`) |

### Operations — [`panels/05-operations.md`](panels/05-operations.md)
| Pannello | Route | Ruoli |
|---|---|---|
| **Connectors** | `/app/admin/connectors` | super-admin |
| **Flows** | `/app/admin/flows` | admin, super-admin, dpo |
| **Eval Harness** | `/app/admin/eval-harness` | admin, super-admin, dpo, editor |
| **MCP Tools** | `/app/admin/mcp-tools` | super-admin |
| **MCP Tokens** | `/app/admin/mcp/tokens` | super-admin |
| **Widget** | `/app/admin/widget` | super-admin |
| **Logs** | `/app/admin/logs` | admin, super-admin |
| **Maintenance** | `/app/admin/maintenance` | admin, super-admin |

> **Notifiche** (campanella del topbar → `/app/admin/notifications`,
> `/preferences`, `/defaults`) non è una voce di sidebar: per-utente, aperta dal
> topbar. Documentata in `panels/02` come appendice.

---

## 5. Quali pannelli sono per-progetto (rilevanti per l'isolamento)

Non tutti i pannelli filtrano per azienda. Distinzione importante per sapere
*dove* il test di isolamento ha senso:

- **Per `project_key`** (qui l'isolamento è osservabile): **Chat** (filtro
  progetto), **Knowledge Base** (picker progetto → albero/grafo), **Doc
  Insights**, **Content Gaps**, **Collections**, **Synonyms**, **Widget**
  (chiavi per progetto), **Connectors**.
- **Solo per `tenant_id`** (aggregati di tutto il tenant, non per azienda):
  **Dashboard**, **AI Insights**, **Compliance**, **AI Act**, **PII Redactor**,
  **Logs** — qui l'isolamento è a livello *tenant*, non *progetto*.
- **Globali RBAC** (nessun dato KB): **Roles**, **Maintenance**, **MCP Tools**.

---

## 6. Il test che conta: i documenti non si mischiano

L'idea: seleziono **l'azienda A** e faccio una domanda la cui risposta esiste
**solo nei documenti dell'azienda B**. Il sistema **deve rifiutare**, non
rispondere con i dati di B.

> **Dove farlo nella UI**: **Chat** → barra filtri **"+ Filter"** → seleziona
> il chip **project** con la `project_key` dell'azienda A → poni la domanda.

### 6.1 — Test negativi (atteso: RIFIUTO)

| # | Azienda selezionata (A) | Domanda | Il fatto vive solo in | Atteso |
|---|---|---|---|---|
| N1 | `rotta-logistics` | *"Ogni quanti anni va rinnovato il CPI?"* | B (Prometeo) | **Rifiuto** (`no_relevant_context`) |
| N2 | `rotta-logistics` | *"Cos'è il programma fedeltà ClubPasso e come si accumulano i punti?"* | C (PassoLibero) | **Rifiuto** |
| N3 | `prometeo-antincendio` | *"Quanto costa il modello Zefiro Run 2.0?"* | C | **Rifiuto** |
| N4 | `prometeo-antincendio` | *"Qual è il prefisso dei codici di tracking delle spedizioni?"* | A (Rotta, `RL-`) | **Rifiuto** |
| N5 | `passolibero-calzature` | *"Cos'è il Protocollo Fenice-7?"* | B | **Rifiuto** |
| N6 | `passolibero-calzature` | *"Quale hub gestisce le merci pericolose ADR?"* | A (`HUB-MI-07`) | **Rifiuto** |

**FAIL = il difetto grave**: se selezionando A la chat risponde nel merito con
un'esca di B (es. cita "CPI 5 anni" o "HUB-MI-07"), **l'isolamento è rotto**.

### 6.2 — Test positivi di controprova (atteso: RISPOSTA corretta)

Stessa domanda, ma con selezionata l'azienda **che possiede davvero** il
documento: la risposta deve contenere il valore esatto e citare un file sotto
`case-studies/<A>/`.

| # | Azienda (A) | Domanda | Risposta attesa |
|---|---|---|---|
| P1 | `rotta-logistics` | *"Entro che ora devo ordinare per la spedizione in giornata?"* | **17:30** |
| P2 | `prometeo-antincendio` | *"Quante ore dura il Corso Salamandra per il rischio alto?"* | **16 ore** |
| P3 | `passolibero-calzature` | *"Entro quanti giorni posso effettuare un reso?"* | **30 giorni** |
| P4 | `prometeo-antincendio` | *"Ogni quanti anni va rinnovato il CPI?"* | **5 anni** (controprova di N1) |
| P5 | `passolibero-calzature` | *"Quanto costa la Zefiro Run 2.0?"* | **€129,90** (controprova di N3) |

### 6.3 — Test di disambiguazione su temi sovrapposti

Temi presenti in **più** aziende con valori **diversi**: il sistema deve dare
*solo* il valore dell'azienda selezionata.

| # | Domanda | `passolibero-calzature` | `rotta-logistics` |
|---|---|---|---|
| D1 | *"Sopra quale importo la spedizione è gratuita?"* | **€60** (BrioExpress) | **Nessuna** spedizione gratuita → deve dirlo / rifiutare, **mai €60** |
| D2 | *"Qual è la politica di reso?"* | resi **30 giorni**, rimborso 5 gg | politica **giacenze/rimborsi logistici** (diversa) |

### 6.4 — Stessa cosa via API (per script/CI)

Endpoint: `POST /api/kb/chat` (Sanctum). Lo scope è in `filters.project_keys`.

```bash
# Esempio test negativo N1 (chiedo a ROTTA un fatto di PROMETEO → atteso rifiuto)
curl -s -X POST https://<host>/api/kb/chat \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json' \
  -H 'Authorization: Bearer <token-sanctum>' \
  -d '{
        "question": "Ogni quanti anni va rinnovato il CPI?",
        "filters": { "project_keys": ["rotta-logistics"] }
      }' | jq '{answer, refusal_reason, confidence}'
```

- **PASS (isolamento ok)** → `refusal_reason` valorizzato (es.
  `"no_relevant_context"`), `answer` di rifiuto, `confidence` ~0, e nessuna
  citazione che punti a `case-studies/prometeo-antincendio/`.
- **FAIL** → `refusal_reason: null` con una risposta che contiene "5 anni" /
  "D.M. 03/08/2015": dati di B trapelati in A.

Per il test positivo P4, stessa chiamata con
`"project_keys": ["prometeo-antincendio"]` → atteso `answer` con **5 anni** e
citazioni sotto `case-studies/prometeo-antincendio/`.

---

## 6.5 — Parole d'ordine delle procedure di emergenza (il test più severo)

Ogni azienda ha **3 procedure di emergenza**, ciascuna con una **parola d'ordine**
(riservata, da sapere *a memoria*), nascoste in punti diversi della documentazione.
**Una procedura ha lo stesso nome in tutte e tre le aziende** — *"Procedura di
Evacuazione Totale"* — **ma con parola d'ordine diversa**. È la trappola di
isolamento più insidiosa: *stessa identica domanda, tre risposte corrette diverse a
seconda del progetto selezionato*. Le altre due procedure hanno **nomi distinti**
per azienda.

### Chiave di risposta (cosa DEVE rispondere ogni azienda)

| Azienda | Evacuazione Totale *(nome condiviso)* | Procedura distinta 2 | Procedura distinta 3 |
|---|---|---|---|
| `rotta-logistics` | **«ORIZZONTE BLU»** | Blocco Movimentazione Merci → **«FERMO QUERCIA»** | Allerta Versamento ADR → **«NEBBIA GIALLA»** |
| `prometeo-antincendio` | **«VENTO DEL NORD»** | Attivazione Squadra Antincendio → **«FALCO 12»** | Isolamento Quadro Elettrico → **«CENERE SILENTE»** |
| `passolibero-calzature` | **«MARE CALMO»** | Chiusura Cassa di Emergenza → **«STELLA NOVE»** | Allerta Sicurezza Negozio → **«PONENTE ROSSO»** |

Le 9 parole d'ordine sono **tutte diverse**. Dove sono nascoste (per il controllo
manuale):

| Parola d'ordine | Documento sorgente |
|---|---|
| ORIZZONTE BLU | `rotta-logistics/rete-hub-magazzini.md` |
| FERMO QUERCIA | `rotta-logistics/runbook-gestione-giacenze.md` |
| NEBBIA GIALLA | `rotta-logistics/merci-pericolose-adr.md` |
| VENTO DEL NORD | `prometeo-antincendio/vie-di-esodo-e-affollamento.md` |
| FALCO 12 | `prometeo-antincendio/protocollo-fenice-7.md` |
| CENERE SILENTE | `prometeo-antincendio/classi-estintori.md` |
| MARE CALMO | `passolibero-calzature/spedizioni-e-consegne.md` |
| STELLA NOVE | `passolibero-calzature/programma-fedelta-clubpasso.md` |
| PONENTE ROSSO | `passolibero-calzature/politica-resi-30-giorni.md` |

### Test E — nome condiviso, risposta diversa per azienda (atteso: la PROPRIA parola)

Poni la **stessa identica domanda** dopo aver selezionato di volta in volta un
progetto diverso:
> *"Qual è la parola d'ordine della Procedura di Evacuazione Totale?"*

| Progetto selezionato | Risposta corretta | FAIL = isolamento rotto |
|---|---|---|
| `rotta-logistics` | **ORIZZONTE BLU** | restituisce VENTO DEL NORD o MARE CALMO |
| `prometeo-antincendio` | **VENTO DEL NORD** | restituisce ORIZZONTE BLU o MARE CALMO |
| `passolibero-calzature` | **MARE CALMO** | restituisce ORIZZONTE BLU o VENTO DEL NORD |

Se due aziende restituiscono la **stessa** parola, o un'azienda restituisce la
parola di un'altra, **l'isolamento è rotto**. È il test singolo più diagnostico
dell'intero pacchetto.

### Test F — procedura che appartiene a un'altra azienda (atteso: RIFIUTO)

| # | Progetto (A) | Domanda | Vive in | Atteso |
|---|---|---|---|---|
| F1 | `rotta-logistics` | *"Qual è la parola d'ordine dell'Attivazione Squadra Antincendio (FALCO 12)?"* | B | **Rifiuto** |
| F2 | `passolibero-calzature` | *"Qual è la parola d'ordine per l'Isolamento Quadro Elettrico?"* | B | **Rifiuto** |
| F3 | `prometeo-antincendio` | *"Qual è la parola d'ordine della Chiusura Cassa di Emergenza?"* | C | **Rifiuto** |
| F4 | `passolibero-calzature` | *"Cosa attiva la parola d'ordine «NEBBIA GIALLA»?"* | A | **Rifiuto** |

> Nota: in un sistema reale le parole d'ordine d'emergenza **non** andrebbero messe
> in una KB interrogabile. Qui sono volutamente il *payload-canarino* del test di
> isolamento: se trapelano tra progetti, il sistema non sta isolando i dati.

---

## 7. Come collaudare gli altri pannelli con questi dati (in breve)

Dettaglio completo nei file `panels/*.md`. In sintesi:

- **Knowledge Base** (`/app/admin/kb`): scegli `rotta-logistics` nel picker →
  l'albero mostra **solo** i suoi 11 documenti; apri `servizi-spedizione` →
  tab **Graph** = grafo 1-hop con **soli nodi di Rotta**; cambia progetto →
  l'albero cambia completamente. *Isolamento KB visibile a occhio.*
- **Doc Insights / Content Gaps**: filtra per progetto → vedi insight / domande
  senza risposta della sola azienda scelta.
- **Synonyms / Collections**: crea un sinonimo o una collection su un progetto →
  non deve comparire negli altri.
- **Dashboard / AI Insights / Logs**: aggregano l'intero **tenant** → vedrai le
  tre aziende insieme nei "Top projects" e nei log di chat (è corretto: lì
  l'isolamento è a livello tenant, non progetto).
- **Maintenance** (`/app/admin/maintenance`): puoi rilanciare in sicurezza
  `kb:rebuild-graph` (non distruttivo) o, da super-admin, provare il flusso
  confirm-token su un comando distruttivo.
- **Approcci rifiutati**: ogni azienda ha un doc `rejected-approach` (es.
  "consegna con droni" per Rotta). Chiedi in chat *"Possiamo consegnare con i
  droni?"* su `rotta-logistics` → la risposta deve riportare che è stato
  **scartato** (iniezione "⚠ approcci rifiutati").

---

## 8. Checklist di accettazione

- [ ] `ingest.sh` termina senza errori; `11` documenti per progetto.
- [ ] Knowledge Base: cambiando progetto l'albero mostra solo i doc di quel progetto.
- [ ] Test negativi N1–N6: **tutti** rifiutano (nessuna esca di un'altra azienda).
- [ ] Test positivi P1–P5: **tutti** rispondono col valore esatto e citano `case-studies/<azienda>/`.
- [ ] Disambiguazione D1–D2: ogni azienda dà solo il proprio valore.
- [ ] Grafo (tab Graph) di un documento: nessun nodo di un'altra azienda.
- [ ] Parole d'ordine (Test E): stessa domanda sull'"Evacuazione Totale" → ogni azienda dà **la propria** parola (ORIZZONTE BLU / VENTO DEL NORD / MARE CALMO), mai quella di un'altra.
- [ ] Parole d'ordine (Test F): chiedere la procedura di un'altra azienda → **rifiuto**.

---

## 9. Pulizia

```bash
docs/case-studies/teardown.sh               # rimuove documenti+chunk+grafo+file su disco
docs/case-studies/teardown.sh --memberships # rimuove anche le ProjectMembership
```

---

## 10. Struttura del pacchetto

```
docs/case-studies/
├── README.md                 ← questo file (setup + test pagina-per-pagina + isolamento)
├── ingest.sh                 ← carica i 3 dataset come 3 progetti (un comando)
├── teardown.sh               ← rimuove i 3 dataset
├── panels/                   ← documentazione pannello-per-pannello della sidebar
│   ├── 01-workspace-chat.md
│   ├── 02-administration.md
│   ├── 03-knowledge.md
│   ├── 04-compliance.md
│   └── 05-operations.md
└── data/                     ← i documenti fittizi (sorgente versionata)
    ├── rotta-logistics/        (11 .md — logistica)
    ├── prometeo-antincendio/   (11 .md — antincendio / VVF)
    └── passolibero-calzature/  (11 .md — scarpe)
```

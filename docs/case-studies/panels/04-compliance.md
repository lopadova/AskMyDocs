# Gruppo "Compliance"

Il gruppo **Compliance** della sidebar admin raccoglie i tre pannelli che presidiano gli obblighi normativi della piattaforma: la conformità all'EU AI Act, la produzione di report di compliance trimestrali a prova di manomissione e la console di redazione/tokenizzazione dei dati personali (PII). Sono strumenti pensati per i ruoli di governance (admin, super-admin e, dove previsto, il DPO) e forniscono evidenze auditabili a revisori e autorità. Alcuni pannelli sono montati dietro pacchetti esterni e feature-flag: quando il flag è spento devono degradare in modo pulito (404/stato "non disponibile"), mai con un 500.

I tre pannelli sono definiti nel gruppo `compliance` di `frontend/src/components/shell/nav-config.ts` e instradati in `frontend/src/routes/index.tsx`.

---

## AI Act

### Percorso
- **Route SPA**: `/app/admin/ai-act-compliance` (più la variante splat `/app/admin/ai-act-compliance/$` per i deep-link interni).
- **Gruppo sidebar**: Compliance → voce "AI Act" (icona `Shield`).
- **Componente**: `frontend/src/features/admin/ai-act-compliance/AiActComplianceView.tsx`.

### Ruoli
`RequireRole roles={['admin', 'super-admin', 'dpo']}` (in `routes/index.tsx`, `AdminAiActComplianceRoute`). Un viewer/editor che apre la URL vede `<AdminForbidden />`. Lato backend gli endpoint del pacchetto sono protetti dalla gate `can:viewAiActCompliance` (super-admin / admin / dpo), come imposto dall'override di middleware in `config/ai-act-compliance.php`.

### A cosa serve
Offre una panoramica live dei registri di conformità all'EU AI Act gestiti dal pacchetto core `padosoft/laravel-ai-act-compliance`. Permette al team di governance di vedere a colpo d'occhio quanti record sono presenti (e in quali stati) per ciascun dominio: incidenti gravi, richieste degli interessati (DSAR), consensi, monitoraggio bias, attestazioni di conformità e coda di revisione umana. È un cruscotto di sola lettura: i record vengono creati e fatti transitare dagli endpoint `/api/admin/ai-act-compliance/*`, non da questa pagina.

### Cosa vedi nella pagina
- **Header** con titolo "AI Act compliance" (`admin-ai-act-compliance-title`), un badge sorgente `padosoft/laravel-ai-act-compliance` (`admin-ai-act-compliance-source`) e il bottone **Refresh** (`admin-ai-act-compliance-refresh`, mostra "Refreshing…" mentre ricarica).
- Un paragrafo descrittivo che richiama gli endpoint `/api/admin/ai-act-compliance/*`.
- Una **griglia di 6 card** (`admin-ai-act-card-<key>`), una per dominio: Incidents, Data-subject requests (DSAR), Consent, Bias monitoring, Attestations, Human reviews. Ogni card mostra il **conteggio** (`admin-ai-act-count-<key>`), una descrizione del registro e, se i record espongono uno stato, una serie di pillole `stato: N`. Con conteggio 0 la card mostra "None recorded yet.".
- **Stati**: `data-state="loading|error|ready"` sul `<section>` `admin-ai-act-compliance`. In caso di errore compare un banner `role="alert"` (`admin-ai-act-compliance-error`) che invita a verificare che il pacchetto sia installato e che il ruolo abbia la gate `viewAiActCompliance`. Non c'è uno stato "empty" dedicato: con tutti i registri a zero la vista resta `ready` (le 6 card mostrano comunque "None recorded yet.").

### Dati / endpoint
- Client API: `frontend/src/features/admin/ai-act-compliance/ai-act.api.ts`.
- `getAiActOverview()` esegue in parallelo una GET per ciascun dominio su `GET /api/admin/ai-act-compliance/{incidents|dsar|consent|bias|attestations|human-reviews}` e calcola conteggio + tally degli stati.
- Gli endpoint sono serviti dal pacchetto `padosoft/laravel-ai-act-compliance`; il routing/sicurezza è imposto da `config/ai-act-compliance.php` (`routes.middleware` con `auth:sanctum` + `tenant.authorize` + `ai-act.tenant-context` + `can:viewAiActCompliance`). Lo scope per tenant è garantito dal middleware `ai-act.tenant-context`.

### Come testarlo con i 3 dataset
1. Accedi come admin/super-admin/dpo e apri `/app/admin/ai-act-compliance`. Attendi `data-state="ready"`: vedi le 6 card. Su un'installazione senza eventi registrati i conteggi sono 0 ("None recorded yet.").
2. **Refresh**: clicca il bottone Refresh e verifica che lo stato passi a "Refreshing…" e poi torni a `ready`, con i conteggi aggiornati.
3. **Isolamento per tenant**: i registri AI Act sono scopati per tenant via `ai-act.tenant-context`. Se i tre progetti fittizi (`rotta-logistics`, `prometeo-antincendio`, `passolibero-calzature`) vivono in tenant distinti, cambiando il tenant attivo (header `X-Tenant-Id`, gated dalla permission `tenant.cross-access`) i conteggi devono cambiare: gli incidenti/DSAR/consensi registrati per `prometeo-antincendio` NON devono comparire navigando con il contesto di `rotta-logistics`. Se i tre progetti condividono lo stesso tenant, la pagina è aggregata a livello tenant e non distingue per project key (questo pannello non ha un selettore di progetto).
4. **Stato di errore / pacchetto assente**: se il pacchetto `padosoft/laravel-ai-act-compliance` non è installato o la gate non è concessa, le GET falliscono e la pagina mostra `data-state="error"` con il banner `admin-ai-act-compliance-error` — non un crash.

---

## Compliance Reports

### Percorso
- **Route SPA**: `/app/admin/compliance/reports`.
- **Gruppo sidebar**: Compliance → voce "Compliance" (icona `Check`).
- **Componente**: `frontend/src/features/admin/compliance/ComplianceReportsView.tsx`.

### Ruoli
`RequireRole roles={['admin', 'super-admin']}` (in `routes/index.tsx`, `AdminComplianceReportsRoute`). Il DPO **non** è ammesso a questo pannello. Lato backend gli endpoint stanno nel gruppo admin `routes/api.php` protetto da `role:admin|super-admin`.

### A cosa serve
Genera **snapshot di compliance trimestrali** per un tenant, ne **verifica gli hash a prova di manomissione** (SHA-256 + HMAC) ed esporta le evidenze in JSON e PDF. È il pannello che produce gli artefatti formali da consegnare a revisori/auditor: ogni report cristallizza lo stato di compliance di un periodo e ne garantisce l'integrità tramite doppio hash.

### Cosa vedi nella pagina
- Titolo **"Compliance Reports"** e sottotitolo che spiega: genera snapshot trimestrali, verifica gli hash tamper-evident, esporta evidenze PDF/JSON.
- **Barra di generazione** (`compliance-reports-generate-bar`):
  - campo testo **tenant** (`compliance-reports-tenant`, default `tenant-acme`),
  - select **trimestre** Q1–Q4 (`compliance-reports-quarter`),
  - campo numerico **anno** (`compliance-reports-year`),
  - bottone **Generate Qn YYYY** (`compliance-reports-generate`, mostra "Generating…" durante la POST; disabilitato se il tenant è vuoto).
- **Tabella report** (`compliance-reports-table`) con colonne: Tenant, Period (`period_start → period_end`), Generated, **Hash Verify** ed **Exports**. Ogni riga (`compliance-reports-row-<id>`) ha:
  - bottone **Verify** (`compliance-reports-verify-<id>`) + badge esito (`compliance-reports-verify-badge-<id>`: `not checked` / `valid` / `invalid`),
  - link download **JSON** (`compliance-reports-download-json-<id>`) e **PDF** (`compliance-reports-download-pdf-<id>`).
- **Stati**: riga "No reports yet." quando la lista è vuota; "Loading…" durante il fetch; "Failed to load reports." in errore. Le righe sono ordinate per `generated_at` decrescente.

### Dati / endpoint
- Client API: `frontend/src/features/admin/compliance/compliance-reports.api.ts`.
- Controller: `app/Http/Controllers/Api/Admin/ComplianceReportController.php`; route in `routes/api.php` sotto il prefisso `/api/admin/compliance`.
  - `GET /api/admin/compliance/reports` → lista (max 100, ordinata desc).
  - `POST /api/admin/compliance/reports` → genera lo snapshot (body `period_start`, `period_end` in `Y-m-d`).
  - `POST /api/admin/compliance/reports/{report}/verify` → ricalcola SHA-256 + HMAC e restituisce `{valid, expected_hash, actual_hash}`.
  - `GET /api/admin/compliance/reports/{report}/json` e `/pdf` → download (PDF via Spatie Browsershot; 500 esplicito se Browsershot manca o restituisce payload vuoto, mai un PDF a 0 byte).
- **Importante (isolamento tenant)**: il campo "tenant" in pagina è solo un'etichetta UX. Il controller **ignora** qualsiasi `tenant_id` del client e scopa sempre su `TenantContext::current()` (`forTenant(...)`), sia in lista sia in generazione — fix R30/C4 anti cross-tenant. Quindi non è possibile leggere o generare report di un altro tenant cambiando il valore digitato nel campo.

### Come testarlo con i 3 dataset
1. **Genera un report**: come admin/super-admin, apri `/app/admin/compliance/reports`. Imposta trimestre/anno e premi **Generate Qn YYYY**. Attendi la nuova riga in tabella con `period_start → period_end` corretti e un `generated_at` popolato.
2. **Verifica integrità**: clicca **Verify** sulla riga. Il badge deve diventare `valid` (hash coerenti). È l'evidenza tamper-evident da mostrare in audit.
3. **Export**: scarica JSON e PDF dalla riga e controlla che il file abbia nome `compliance-report-<tenant>-<start>-<end>.{json|pdf}` e contenuto non vuoto.
4. **Check di isolamento (per-tenant, non per-project)**: questo pannello è scopato per **tenant**, non per project key, e il campo tenant digitato non aggira lo scope server-side. Se i tre progetti (`rotta-logistics`, `prometeo-antincendio`, `passolibero-calzature`) condividono lo stesso tenant, vedrai un unico set di report aggregati. Se invece sono su tenant distinti, generando un report mentre il contesto attivo è il tenant di `prometeo-antincendio` la riga NON deve comparire quando l'app gira con il tenant di `rotta-logistics` (cambio tenant via header `X-Tenant-Id`, gated da `tenant.cross-access`). Prova a digitare il tenant di un altro progetto nel campo: la lista NON deve esporre i report dell'altro tenant, perché il controller usa comunque il tenant del contesto risolto.
5. **Failure path PDF**: se `spatie/browsershot` non è installato, il download PDF deve rispondere 500 con messaggio "PDF rendering failed." (R14), non un file vuoto a 200.

---

## PII Redactor

### Percorso
- **Route SPA**: `/app/admin/pii-redactor`.
- **Gruppo sidebar**: Compliance → voce "PII Redactor" (icona `Eye`).
- **Componente host**: `frontend/src/features/admin/pii-redactor/PiiRedactorView.tsx` (cross-mount dell'app React del pacchetto `padosoft/laravel-pii-redactor-admin` v1.0.2, in `cross-mount/App.tsx`).

### Ruoli
`RequireRole roles={['admin', 'super-admin', 'dpo']}` (in `routes/index.tsx`, `AdminPiiRedactorRoute`). Lato backend la gate è `can:viewPiiRedactorAdmin` (super-admin / dpo / admin). Le abilità più sensibili sono ulteriormente ristrette: **detokenise** (riportare i token al valore originale) è ammessa solo a super-admin / dpo; la visualizzazione dei **raw samples** solo a super-admin (vedi `abilities` in `PiiRedactorView.tsx` e le gate registrate nell'`AppServiceProvider`).

### A cosa serve
È la console operativa per la **redazione e tokenizzazione dei dati personali (PII)**: consente di analizzare testo, applicare strategie di mascheramento, ispezionare la token-map, de-tokenizzare in modo controllato (per i ruoli autorizzati), gestire detector e regole custom e leggere il log di audit delle operazioni. Serve a soddisfare gli obblighi GDPR/AI Act di minimizzazione e tracciabilità del trattamento dei dati personali.

### Cosa vedi nella pagina
La vista host (`admin-pii-redactor-host`, `data-mount="cross-mount"`, `data-state="ready"`) monta l'app del pacchetto **senza** la sua sidebar (la rail unica dell'host fornisce già la navigazione): le sezioni sono rese come tab strip in-content. Le 8 sezioni (in `cross-mount/App.tsx`) sono:
- **Overview** — stato/health del redactor (gestisce loading/error con placeholder "unavailable", R14).
- **Playground** — analisi/redazione di testo on-demand (anche via shortcut `admin-pii-redactor-shortcut-playground`).
- **Audit log** — eventi di audit delle operazioni PII.
- **Token map** — mappatura token ↔ valore.
- **Detokenise** — riconversione token→valore (visibile/abilitata solo per i ruoli con l'abilità `detokenise`).
- **Detectors** — detector di PII disponibili.
- **Custom rules** — regole di rilevamento personalizzate.
- **Settings** — configurazione (pannello JSON).

Gli errori API bubbleano tramite `AdminApiError` e compaiono come `InlineNotice`/`pra-alert`, mai silenziati (R7/R14).

### Dati / endpoint
- Le API sono servite dal pacchetto sotto il prefisso `admin/pii-redactor/api` (costante `PII_REDACTOR_API_BASE` in `PiiRedactorView.tsx`); il client del cross-mount è `cross-mount/adminApi.ts`.
- Esempi di endpoint del pacchetto consumati dall'app: `audit-events`, `tokens`, `settings`, oltre alle azioni di scan/redact/detokenise (throttling configurato in `config/pii-redactor-admin.php`).
- L'host esiste anche come endpoint nativo `GET /api/admin/pii/strategy` (`PiiStrategyController`, gate `can:viewPiiRedactorAdmin`), separato dalle route del pacchetto.

### Feature-flag e degradazione (importante)
Il pannello è montato dietro un **feature-flag**: `config/pii-redactor-admin.php` → `'enabled' => env('PII_REDACTOR_ADMIN_ENABLED', false)` — **default OFF**. Quando il flag è spento il pacchetto **non registra** le sue route `admin/pii-redactor/api/*`, quindi le chiamate dell'app cross-mount falliscono con 404/403 e devono **degradare in modo pulito** (banner di errore inline / sezioni in stato error), non un 500 (vincolo R43: un flag booleano va sano sia in OFF sia in ON). La voce di sidebar e la route SPA restano comunque presenti; chi non ha la gate `viewPiiRedactorAdmin` riceve comunque un 403 lato HTTP (defence-in-depth, indipendente dal flag). Le abilità FE (`view`/`detokenise`/`rawSamples`) sono solo affordance UX: la difesa reale è la gate BE.

### Come testarlo con i 3 dataset
1. **Flag ON**: imposta `PII_REDACTOR_ADMIN_ENABLED=true` e concedi `viewPiiRedactorAdmin` ai ruoli giusti. Apri `/app/admin/pii-redactor` come admin/super-admin/dpo: l'host (`admin-pii-redactor-host`) monta l'app e mostra le tab Overview/Playground/Audit/Token map/Detokenise/Detectors/Custom rules/Settings.
2. **Playground**: nella sezione Playground incolla un testo di esempio coerente con un progetto (es. un'anagrafica cliente di `passolibero-calzature` con nome/indirizzo/email) e verifica che le PII vengano rilevate e tokenizzate; controlla che la token-map registri le sostituzioni.
3. **Detokenise / RBAC sull'abilità**: come **dpo** o **super-admin** verifica che la sezione Detokenise sia operativa; come **admin** (privo dell'abilità `detokenise`) verifica che l'azione sia inibita/assente. I **raw samples** devono essere visibili solo a super-admin.
4. **Audit**: dopo aver fatto scan/redact, controlla che la sezione Audit log riporti gli eventi delle operazioni.
5. **Check di isolamento per tenant**: PII Redactor è scopato per tenant (le route passano per `tenant.authorize`). Se i tre progetti vivono in tenant distinti, token-map e audit log relativi a `prometeo-antincendio` non devono comparire operando nel contesto di `rotta-logistics` o `passolibero-calzature`. Se condividono il tenant, i dati sono aggregati a livello tenant (il pannello non ha un selettore di project key).
6. **Flag OFF (degradazione)**: imposta `PII_REDACTOR_ADMIN_ENABLED=false`. La route SPA è ancora raggiungibile ma le API del pacchetto non esistono: la pagina deve mostrare gli errori inline/sezioni in stato error in modo pulito, **senza** schermata bianca o 500. Questo è il caso da verificare a ogni deploy fresco (R43: default OFF).

# Gruppo "Administration"

Il gruppo **Administration** della sidebar SPA raccoglie i quattro pannelli di governance trasversale dell'istanza AskMyDocs: la **Dashboard** operativa (KPI + salute del sistema + serie temporali), gli **AI Insights** giornalieri sulla knowledge base, e i due pannelli di **RBAC** (Users e Roles) con cui si gestiscono account, ruoli e permessi Spatie. Sono pannelli a livello di tenant/organizzazione — non per-progetto: vedono in modo aggregato l'attività di tutti i progetti del tenant attivo. Tutte le query sono comunque scopate sul tenant corrente (R30), quindi un tenant non vede mai i dati di un altro.

> Nota sui dataset fittizi usati negli esempi di test: i tre progetti sono `rotta-logistics` (logistica/spedizioni), `prometeo-antincendio` (consulenza normativa antincendio / vigili del fuoco) e `passolibero-calzature` (vendita scarpe). Per Dashboard e AI Insights, che lavorano a livello aggregato, i tre progetti si manifestano nelle card "Top projects" e "Activity feed" (Dashboard) e nelle righe per-documento (Insights), dove ciascuna riga porta il proprio `project_key`.

---

## Dashboard

### Percorso
- **Route SPA**: `/app/admin` (redirect legacy: `/app/dashboard` → `/app/admin`).
- **Gruppo sidebar**: Administration › Dashboard (icona `Grid`).
- Componente: `frontend/src/features/admin/dashboard/DashboardView.tsx`.

### Ruoli
Visibile solo a **admin** e **super-admin** (`RequireRole roles={['admin', 'super-admin']}` in `routes/index.tsx`, funzione `AdminRoute`). Un **viewer**, **editor** o **dpo** che apre l'URL direttamente vede la pagina `<AdminForbidden />`. A livello API il gruppo è protetto da `auth:sanctum` + `tenant.authorize` + `role:admin|super-admin`.

### A cosa serve
È la console operativa "a colpo d'occhio" dell'istanza. Mostra lo stato di salute dei sottosistemi (DB, pgvector, coda, disco KB, provider embedding e chat), un set di KPI sulla knowledge base e sul traffico chat, e alcune serie temporali (volume chat, consumo token, distribuzione rating, progetti più attivi, feed attività). Serve all'amministratore per accorgersi rapidamente di un provider AI down, di una coda con job falliti o di un calo di gradimento delle risposte.

### Cosa vedi nella pagina
- **Intestazione**: titolo "Dashboard" e sottotitolo "Rolling 7-day view · refresh every 30 seconds". La finestra temporale è **fissa a 7 giorni** ed è hardcoded nel componente (`DAYS = 7`): nella versione attuale della SPA **non c'è un selettore di periodo né un filtro progetto** nella UI della Dashboard (l'endpoint backend accetta i parametri `project` e `days`, ma `DashboardView` non li invia). Container con `data-testid="admin-dashboard"` e `data-state` che riassume loading/error/empty/ready di tutte le sezioni.
- **Health strip** (`data-testid="dashboard-health"`): striscia di chip sempre visibile con 6 controlli — DB, pgvector, Queue, KB disk, Embeddings, Chat AI — ognuno con `data-testid` dedicato (es. `health-db`, `health-pgvector`, `health-queue`, `health-kb-disk`, `health-embeddings`, `health-chat`) e stato `ok` / `degraded` / `down`. In coda mostra l'orario dell'ultimo check. Si auto-aggiorna ogni 15 secondi.
- **KPI strip** (`data-testid="kpi-strip"`): 6 tessere — **Docs** (con % canonical), **Chunks** (con MB usati), **Chats (window)**, **Avg latency**, **Cache hit** (percentuale), **Canonical** (% coverage, con conteggio job pending/failed).
- **Griglia di card grafiche** (auto-fit): **Chat volume** (volume chat per giorno), **Token burn** (consumo token per provider), **Rating donut** (distribuzione positive/negative/unrated), **Top projects (7d)** (`data-testid="top-projects-card"`, barre orizzontali per `project_key`, ogni riga `top-project-<project_key>`).
- **Activity feed** (`data-testid="activity-feed-card"`): elenco delle attività recenti (sorgente `chat` o `audit`) con attore, azione, target, progetto e tempo relativo.
- Ogni card espone empty/loading/error proprio (`data-state`), per esempio `top-projects-empty` e `activity-feed-empty`.

### Dati / endpoint
Tre hook TanStack Query in `use-admin-metrics.ts` su `adminApi` (`admin.api.ts`):
- `GET /api/admin/metrics/overview?days=7` → KPI strip (cache server-side 30s, poll FE 30s).
- `GET /api/admin/metrics/series?days=7` → chat volume, token burn, rating distribution, top projects, activity feed (cache 30s, poll 30s).
- `GET /api/admin/metrics/health` → health strip (non cachato; poll FE 15s).

Backend: `DashboardMetricsController` (con `AdminMetricsService` + `HealthCheckService`). Il controller accetta `project` e `days` (clampato 1–90) e cacha per chiave `(project, days)`; il payload health include anche `pii_redactor_config`. Le aggregazioni sono scopate sul tenant attivo (R30).

### Come testarlo con i 3 dataset
1. Ingestare documenti e generare qualche turno di chat per ognuno dei tre progetti `rotta-logistics`, `prometeo-antincendio`, `passolibero-calzature` nel medesimo tenant.
2. Aprire `/app/admin` come admin/super-admin. Verificare:
   - **Health strip**: tutti i chip verdi (`ok`) se DB/pgvector/coda/disco/provider sono configurati; un provider AI senza chiave appare `degraded`/`down`.
   - **KPI strip**: "Docs" e "Chunks" riflettono la somma dei tre progetti del tenant; "Chats (window)" conta i turni degli ultimi 7 giorni.
   - **Top projects (7d)**: deve elencare tutte e tre le chiavi (`rotta-logistics`, `prometeo-antincendio`, `passolibero-calzature`) ordinate per numero di chat, con la barra del progetto più attivo al 100%.
   - **Activity feed**: le righe recenti mostrano il `project_key` accanto a ciascun evento.
3. **Check di isolamento (livello tenant)**: la Dashboard non ha filtro progetto in UI, quindi mostra l'aggregato dei tre progetti *del tenant corrente*. Per verificare l'isolamento, accedere come amministratore di un **altro tenant**: KPI, Top projects e Activity feed NON devono contenere nessuno dei tre progetti sopra né i loro conteggi. I numeri devono ripartire dai dati del solo tenant attivo.

---

## AI Insights

### Percorso
- **Route SPA**: `/app/admin/insights` (redirect legacy: `/app/insights` → `/app/admin/insights`).
- **Gruppo sidebar**: Administration › AI Insights (icona `Sparkles`).
- Componente: `frontend/src/features/admin/insights/InsightsView.tsx`.

### Ruoli
Visibile a **admin** e **super-admin** (`RequireRole roles={['admin', 'super-admin']}`, funzione `AdminInsightsRoute`). Viewer/editor/dpo che aprono l'URL vedono `<AdminForbidden />`. Il ricalcolo (compute) è ulteriormente ristretto: oltre a `role:admin|super-admin`, la rotta `POST /api/admin/insights/compute` richiede il permesso `commands.destructive` (di fatto super-admin) ed è throttlata.

### A cosa serve
Espone i segnali AI calcolati una volta al giorno sull'intera knowledge base del tenant: documenti candidati alla promozione canonica, documenti canonici orfani, documenti che avrebbero bisogno di tag, lacune di copertura (domande senza risposta), documenti stantii o con rating negativo, e un report di qualità sulla distribuzione dei chunk. Serve al curatore della KB per decidere su cosa intervenire (promuovere, taggare, aggiornare, colmare gap) senza dover ispezionare a mano migliaia di documenti.

### Cosa vedi nella pagina
- **Intestazione**: titolo "AI Insights" e sottotitolo "Daily-computed signals across the knowledge base." con l'orario dell'ultimo run (`computed_at`) quando presente. Container `data-testid="insights-view"` con `data-state`.
- **Stato vuoto** (`data-testid="insights-no-snapshot"`): se nessuno snapshot è ancora stato calcolato, mostra l'istruzione a lanciare `php artisan insights:compute` o `POST /api/admin/insights/compute`.
- **Stato loading** (`insights-loading`) e **stato error** (`insights-error`) gestiti esplicitamente.
- **Highlight strip** (`data-testid="insights-highlights"`): 3 KPI di sintesi — **Promotable docs** (`insights-highlight-promotions`), **Orphan canonical** (`insights-highlight-orphans`), **Need tags** (`insights-highlight-tags`) — con i rispettivi conteggi.
- **Griglia di 6 card** (auto-fit): **PromotionSuggestionsCard**, **OrphanDocsCard**, **SuggestedTagsCard**, **CoverageGapsCard**, **StaleDocsCard**, **QualityReportCard**. Ogni card elenca righe per-documento con `project_key`, `slug`/`title` e il dato specifico (reason+score, ultimo uso, tag proposti, ecc.).
- **Nota**: la lettura della pagina non innesca alcuna chiamata LLM (legge solo lo snapshot pre-calcolato). Il bottone "Recompute now" è citato nel commento del componente ma nella versione attuale **non è renderizzato in `InsightsView`**: il ricalcolo si fa via API/CLI (vedi sotto).

### Dati / endpoint
Hook in `insights.api.ts`:
- `GET /api/admin/insights/latest` → ultimo snapshot (404 = "nessuno snapshot ancora", trattato dalla UI come stato `empty`).
- `GET /api/admin/insights/{date}` → snapshot di un giorno specifico (404 su data assente o malformata).
- `POST /api/admin/insights/compute` → ricalcolo on-demand (super-admin, throttle, audit su `admin_command_audits`), risponde 202.
- `GET /api/admin/insights/document/{id}/ai-suggestions` → tag AI per-documento (unica chiamata LLM on-demand, usata dal tab Meta della KB; 503 su errore provider).

Backend: `AdminInsightsController` + `AiInsightsService`; gli snapshot vivono in `admin_insights_snapshots` (modello `AdminInsightsSnapshot`) e sono **scopati per tenant** (`forTenant(...)`, R30). Il job giornaliero è `InsightsComputeCommand` (`insights:compute`).

### Come testarlo con i 3 dataset
1. Avere documenti dei tre progetti nel tenant; lanciare `php artisan insights:compute` (o `POST /api/admin/insights/compute` come super-admin).
2. Aprire `/app/admin/insights` come admin/super-admin:
   - Se lo snapshot esiste, l'**Highlight strip** mostra i conteggi aggregati e le 6 card si popolano. Le righe nelle card (es. promozioni, orfani, tag proposti) riportano il `project_key`: devono comparire documenti di `rotta-logistics`, `prometeo-antincendio` e `passolibero-calzature` mescolati, ognuno etichettato col proprio progetto.
   - Se non è mai stato calcolato, deve apparire `insights-no-snapshot` con le istruzioni.
3. **Check di isolamento (livello tenant)**: lo snapshot è per-tenant. Eseguendo il compute e aprendo gli Insights da un **tenant diverso**, le righe per-documento NON devono includere nessun documento dei tre progetti sopra. Verificare anche che `GET /api/admin/insights/latest` restituisca lo snapshot del solo tenant attivo (l'ordinamento è per `snapshot_date` ma sempre filtrato `forTenant`).

---

## Users

### Percorso
- **Route SPA**: `/app/admin/users` (redirect legacy: `/app/users` → `/app/admin/users`).
- **Gruppo sidebar**: Administration › Users (icona `Users`).
- Componente: `frontend/src/features/admin/users/UsersView.tsx`.

### Ruoli
Visibile a **admin** e **super-admin** (`RequireRole roles={['admin', 'super-admin']}`, funzione `AdminUsersRoute`). Viewer/editor/dpo vedono `<AdminForbidden />`. Tutti gli endpoint sono sotto `auth:sanctum` + `tenant.authorize` + `role:admin|super-admin`.

### A cosa serve
È la gestione degli account: ricerca/filtro utenti, creazione, modifica (profilo + ruoli + password), attivazione/disattivazione, soft-delete e restore, reinvio invito, e la gestione delle **appartenenze ai progetti** (project memberships) con relativi scope. È il punto in cui si assegnano agli utenti i 5 ruoli RBAC (`super-admin`, `admin`, `dpo`, `editor`, `viewer`) e si decide a quali progetti hanno accesso.

### Cosa vedi nella pagina
- **Intestazione**: titolo "Users", sottotitolo "Manage accounts, roles and project memberships." e bottone **New user** (`data-testid="users-new"`). Container `data-testid="admin-users"` con `data-state`.
- **Barra filtri** (`data-testid="users-filter-bar"`):
  - campo ricerca per nome/email (`users-filter-q`);
  - select ruolo (`users-filter-role`, opzioni: viewer / editor / admin / super-admin);
  - select stato (`users-filter-active`: Active+inactive / Active only / Inactive only);
  - checkbox "Include deleted" (`users-filter-with-trashed`) per mostrare anche i soft-deleted.
- **Tabella utenti** (`UsersTable`): righe con nome, email, ruoli, stato attivo, e azioni inline (apri/edit, attiva-disattiva, restore, delete).
- **Drawer utente** (`UserDrawer`, `data-testid="user-drawer"`): slide-in con tre tab — **Profile** (UserForm: nome, email, password, attivo, chip ruoli + bottone "Resend invite" in edit), **Roles** (anteprima ruoli assegnati + permessi effettivi), **Project memberships** (`MembershipEditor`). In create-mode è abilitata solo la tab Profile.
- Toast di feedback per ogni mutazione (creato/aggiornato/eliminato/ripristinato/attivato).

### Dati / endpoint
Hook in `users.api.ts` su `adminUsersApi` + `useRoles()` per popolare i ruoli:
- `GET /api/admin/users` (con `q`, `role`, `active`, `with_trashed`, `per_page`).
- `POST /api/admin/users`, `GET/PATCH /api/admin/users/{id}`, `DELETE /api/admin/users/{id}` (`?force=1` per hard delete).
- `POST /api/admin/users/{id}/restore`, `PATCH /api/admin/users/{id}/active`, `POST /api/admin/users/{id}/resend-invite`.
- Memberships: `GET/POST /api/admin/users/{id}/memberships`, `PATCH/DELETE /api/admin/memberships/{id}`.

Backend: `UserController` + `ProjectMembershipController`. Le membership e gli utenti sono scopati per tenant (binding `forTenant` su `membership`, R30).

> Nota: il picker progetti del `MembershipEditor` è alimentato dalla lista REALE dei progetti del tenant (`useKbProjects()` → `GET /api/admin/kb/projects`, R18: mai un set hard-coded). Dopo aver eseguito `ingest.sh`, le tre aziende del case study (`rotta-logistics`, `prometeo-antincendio`, `passolibero-calzature`) compaiono quindi tra le opzioni del drawer e si possono assegnare normalmente.

### Come testarlo con i 3 dataset
1. Aprire `/app/admin/users` come admin/super-admin.
2. **Create**: cliccare "New user", compilare il form (es. un utente "viewer di logistica"), assegnare il ruolo `viewer`, salvare → toast `toast-user-created` e l'utente compare in tabella.
3. **Filtri**: digitare parte dell'email in `users-filter-q`; selezionare `viewer` in `users-filter-role` → la tabella si restringe. Attivare "Include deleted" per vedere gli utenti soft-deleted.
4. **Edit + memberships**: aprire un utente, tab "Project memberships", e assegnare uno dei progetti del case study con il relativo scope (le opzioni arrivano da `GET /api/admin/kb/projects`). Tab "Roles" per verificare ruoli e permessi effettivi.
5. **Toggle/Delete/Restore**: disattivare un utente, eliminarlo (soft), poi con "Include deleted" attivo ripristinarlo.
6. **Check di isolamento (livello tenant)**: la lista utenti è scopata sul tenant attivo. Le membership assegnate qui valgono per i `project_key` del tenant corrente; un amministratore di un altro tenant NON deve vedere questi utenti né le loro membership ai progetti del case study. Verificare inoltre che un'azione su un utente/membership non restituisca dati di un tenant diverso (le binding route `forTenant` impediscono IDOR cross-tenant).

---

## Roles

### Percorso
- **Route SPA**: `/app/admin/roles`.
- **Gruppo sidebar**: Administration › Roles (icona `Shield`).
- Componente: `frontend/src/features/admin/roles/RolesView.tsx`.

### Ruoli
Visibile a **admin** e **super-admin** (`RequireRole roles={['admin', 'super-admin']}`, funzione `AdminRolesRoute`). Viewer/editor/dpo vedono `<AdminForbidden />`. API sotto `role:admin|super-admin`.

### A cosa serve
Gestisce i ruoli Spatie e la matrice dei permessi del sistema RBAC. I 5 ruoli di riferimento sono **super-admin**, **admin**, **dpo**, **editor**, **viewer**; `super-admin` e `admin` sono **ruoli di sistema protetti** (non rinominabili e non eliminabili). Da qui si creano nuovi ruoli, si modifica l'insieme di permessi assegnati e si vede quanti utenti hanno ciascun ruolo.

### Cosa vedi nella pagina
- **Intestazione**: titolo "Roles", sottotitolo "Spatie-backed roles and permission matrix." e bottone **New role** (`data-testid="roles-new"`). Container `data-testid="admin-roles"` con `data-state`.
- **Tabella ruoli** (`data-testid="roles-table"`): colonne **Role** / **Permissions** (count assegnati su totale catalogo) / **Users** (conteggio utenti) / **Actions**. Ogni riga è `roles-row-<name>`; i ruoli protetti mostrano un'icona scudo e hanno `data-protected="true"`. Azioni inline: **Edit** (`roles-row-<name>-edit`) e **Delete** (`roles-row-<name>-delete`, disabilitato sui ruoli protetti — il backend rinforza con 409). Stati `roles-loading` / `roles-error` / `roles-empty`.
- **RoleDialog** (`data-testid="role-dialog"`): modale create/edit con campo **Name** (disabilitato per `super-admin`/`admin`, con avviso `role-dialog-name-protected`) e **matrice permessi** (`role-dialog-matrix`) raggruppata per dominio: ogni dominio (`role-perm-domain-<domain>`) ha un toggle "Select all / Unselect all" (`role-perm-<domain>-toggle-all`) e checkbox per singolo permesso (`role-perm-<name>`). In fondo i bottoni **Cancel** e **Save changes / Create role**.
- Toast di feedback per create/update/delete dei ruoli.

### Dati / endpoint
Hook in `roles.api.ts`:
- `GET /api/admin/roles` (con `users_count` e `permissions`).
- `POST /api/admin/roles`, `PATCH/PUT /api/admin/roles/{id}`, `DELETE /api/admin/roles/{id}`.
- `GET /api/admin/permissions` → catalogo permessi (`AdminPermissionCatalogue`, con `grouped` per dominio) usato per riempire la matrice.

Backend: `RoleController` + `PermissionController` (Spatie). I ruoli e i permessi sono entità globali del sistema RBAC (non per-progetto).

### Come testarlo con i 3 dataset
1. Aprire `/app/admin/roles` come admin/super-admin. La tabella deve elencare i 5 ruoli di sistema (`super-admin`, `admin`, `dpo`, `editor`, `viewer`); `super-admin` e `admin` con scudo e Delete disabilitato.
2. **Create**: cliccare "New role", dare un nome (es. `logistics-curator`), aprire la matrice permessi, usare "Select all" su un dominio rilevante, salvare → toast `toast-role-created` e nuova riga in tabella.
3. **Edit protetto**: aprire `super-admin` → il campo Name è disabilitato con l'avviso; i permessi restano comunque modificabili.
4. **Delete guard**: tentare il Delete su un ruolo protetto → bottone disabilitato lato UI; un tentativo via API restituisce 409, propagato come toast `toast-role-error`.
5. **Conteggio utenti**: dopo aver assegnato un ruolo a un utente nel pannello Users, la colonna **Users** del ruolo corrispondente deve aggiornarsi.
6. **Isolamento**: i ruoli/permessi NON sono per-progetto, quindi non si applica il filtro per `rotta-logistics` / `prometeo-antincendio` / `passolibero-calzature`. L'isolamento qui è quello RBAC: un utente con ruolo `viewer` o `editor` NON deve poter aprire `/app/admin/roles` (vede `<AdminForbidden />`) né effettuare mutazioni (403 dal backend).

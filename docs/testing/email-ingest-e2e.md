# Test end-to-end: ingest di e-mail reali via IMAP

Runbook operativo per verificare **tutto** il percorso
`e-mail vere → casella IMAP → connettore → ingest → chat` su aziende già
presenti nel sistema. Non si seedano righe finte nel DB: si inviano messaggi
**veri** dentro caselle IMAP **vere** (Gmail di test), poi si fa l'ingest da
quelle caselle, esattamente come in produzione.

> Harness di **dev/test**. Niente di tutto questo va abilitato in produzione
> (le credenziali `CONNECTOR_TEST_*` restano vuote sui deploy reali). È tooling
> CLI: per scelta non ha UI né endpoint HTTP dedicati (R44 — eccezione motivata).

---

## 0. Panoramica del flusso

```mermaid
flowchart LR
    F[TestEmailFixtures<br/>account + e-mail] -->|mail:seed-imap<br/>IMAP APPEND| M[(Casella Gmail<br/>INBOX)]
    M -->|connector:imap:install --sync<br/>ConnectorSyncJob| I[IngestDocumentJob<br/>parse→chunk→embed→persist]
    I --> KB[(knowledge_documents<br/>+ knowledge_chunks<br/>project_key = azienda)]
    KB -->|membership esistente| C[Chat per account<br/>risposta grounded + citazione]
```

Tre comandi compongono l'harness (tutti idempotenti, tenant `default`):

| Comando | Ruolo |
|---|---|
| `demo:list-companies` | Discovery read-only: quali aziende esistono, con quanti documenti/membri/connettori. |
| `mail:seed-imap` | Consegna (APPEND) le e-mail di test dentro le caselle IMAP. |
| `connector:imap:install` | Installa il connettore IMAP per azienda e (con `--sync`) avvia l'ingest. |

La sorgente dati unica è
[`database/seeders/TestEmailFixtures.php`](../../database/seeders/TestEmailFixtures.php):
account IMAP (`ACCOUNTS`) + e-mail per azienda (`EMAILS`). Ogni e-mail porta un
**"fatto-esca"** unico (un codice/nome/numero, es. `RL-2024-0815`, `Protocollo
Fenice-7`, `ClubPasso Aero`) che **non deve** comparire nelle risposte di
un'altra azienda: è il rilevatore di contaminazione del test di isolamento.

---

## 1. Prerequisiti

### 1.1 Aziende già presenti
Le fixtures coprono le 3 aziende del `CaseStudyUsersSeeder` (tenant `default`):

| project_key | Azienda | Utente per la chat (pwd `password`) |
|---|---|---|
| `rotta-logistics` | Rotta Sicura Logistics | `rotta@case-study.local` |
| `prometeo-antincendio` | Prometeo Sicurezza Antincendio | `prometeo@case-study.local` |
| `passolibero-calzature` | PassoLibero Calzature | `passolibero@case-study.local` |

Assicurati che esistano (e abbiano già la documentazione markdown):

```bash
php artisan db:seed --class=Database\\Seeders\\RbacSeeder
php artisan db:seed --class=Database\\Seeders\\CaseStudyUsersSeeder
php artisan demo:list-companies
```

> Puntando il connettore al **project_key esistente** dell'azienda, l'utente
> case-study (già membro) vede subito le e-mail ingerite — nessun wiring extra.
> Se invece usi un project_key nuovo, ricordati la membership (vedi §6).

### 1.2 Caselle Gmail di test
Per ogni azienda serve una casella Gmail dedicata (le indica `ACCOUNTS`):
`rotta.test.askmydocs@gmail.com`, `prometeo.test.askmydocs@gmail.com`,
`passolibero.test.askmydocs@gmail.com` (oppure cambia gli indirizzi nel fixture).

Su ciascun account Gmail:
1. Attiva la verifica in due passaggi.
2. Crea una **App Password** (Google Account → Sicurezza → Password per le app).
   La password normale **non** funziona con IMAP.
3. IMAP è abilitato di default sugli account Google Workspace/Gmail.

### 1.3 `.env`
Compila (dev/test) le variabili dell'harness — vedi `.env.example`:

```dotenv
CONNECTOR_TEST_IMAP_HOST=imap.gmail.com
CONNECTOR_TEST_IMAP_PORT=993
CONNECTOR_TEST_IMAP_ENCRYPTION=ssl
CONNECTOR_TEST_IMAP_DATE_WINDOW_DAYS=365
CONNECTOR_TEST_ROTTA_PASSWORD=<app-password-rotta>
CONNECTOR_TEST_PROMETEO_PASSWORD=<app-password-prometeo>
CONNECTOR_TEST_PASSOLIBERO_PASSWORD=<app-password-passolibero>
```

### 1.4 Coda + provider AI (per l'ingest reale)
L'ingest è asincrono e genera embedding → richiede:

- **Coda attiva**: o `QUEUE_CONNECTION=sync` (ingest inline, più semplice per il
  test) **oppure** un worker in parallelo: `php artisan queue:work`.
- **Provider AI** configurati: `AI_EMBEDDINGS_PROVIDER` (+ relativa API key) per
  generare gli embedding in ingest, e `AI_PROVIDER` (+ key) per la chat finale.

---

## 2. Discovery — cosa c'è già

```bash
php artisan demo:list-companies            # tutte le aziende/progetti
php artisan demo:list-companies --tenant=default
```

Mostra per ogni `project_key`: nome, #documenti, #chunk, membri (chi può
chattare) e se c'è un connettore. Evidenzia anche i **project_key orfani**
(documenti ma nessuna riga `projects`/membership): è lo stato "la chat non trova
niente". Usalo prima e dopo l'ingest per misurare la differenza.

---

## 3. Anteprima delle e-mail (dry-run, senza credenziali)

```bash
php artisan mail:seed-imap --all --dry-run
```

Costruisce e mostra ogni messaggio senza inviare nulla né leggere le password.
Utile per controllare contenuti/oggetti prima della consegna reale.

---

## 4. Consegna delle e-mail nelle caselle (APPEND)

```bash
# tutte le aziende
php artisan mail:seed-imap --all

# una singola azienda
php artisan mail:seed-imap --project=rotta-logistics

# re-run pulito: prima rimuove i messaggi di test già presenti (DISTRUTTIVO,
# tocca SOLO i messaggi con header X-AskMyDocs-Seed)
php artisan mail:seed-imap --all --purge
```

Dettagli:
- I messaggi vengono **APPESI** in `INBOX` via webklex; la data di consegna
  (INTERNALDATE) è `now()`, così le e-mail (datate 2024 nelle fixtures) restano
  dentro la finestra `date_window_days` del connettore.
- Su errori IMAP **transitori** (timeout/connessione) il comando attende e
  ritenta (`--retries`, `--retry-delay`); su errori di **autenticazione** si
  ferma subito con messaggio chiaro (R42/R14).
- Verifica anche da web: apri la casella Gmail e controlla che le e-mail siano
  in arrivo.

---

## 5. Installazione connettore + ingest

```bash
# installa il connettore IMAP per tutte le aziende e avvia subito il sync
php artisan connector:imap:install --all --sync

# singola azienda, attore specifico per l'audit (created_by)
php artisan connector:imap:install --project=prometeo-antincendio --actor=super@demo.local --sync
```

Cosa fa:
- Riusa `ConfigureConnectorService` → **verifica davvero** le credenziali
  (ping IMAP) prima di portare l'installazione ad `ACTIVE`.
- Salva `config_json` con `connection.*`, `project_key = <azienda>`,
  `folders.include = ["INBOX"]` (solo INBOX: evita i doppioni delle cartelle
  virtuali Gmail) e `date_window_days`. La password va nel **vault cifrato**,
  mai in `config_json`.
- Con `--sync` accoda un `ConnectorSyncJob` → ingest delle e-mail nuove.

Se NON usi `--sync`, l'ingest parte comunque dallo scheduler (ogni 15 min) o
puoi ridispacciare il job manualmente. Ricorda la coda (§1.4).

---

## 6. Membership (solo se usi un project_key nuovo)

L'ingest da connettore **non** crea automaticamente `projects` né
`project_memberships`. Se hai puntato il connettore a un `project_key` che non
ha membri, la chat non troverà nulla. Sblocca con i comandi esistenti:

```bash
php artisan auth:grant rotta@case-study.local viewer --project=<nuovo-project_key>
# oppure, per creare anche la riga projects + utente:
php artisan demo:seed-user --email=rotta@case-study.local --project=<key> --role=viewer
```

Con le 3 aziende case-study e il connettore puntato al loro project_key
esistente, **questo passo non serve**.

---

## 7. Verifica ingest

```bash
php artisan demo:list-companies
```

Il conteggio `docs`/`chunks` dell'azienda deve essere aumentato delle e-mail
ingerite. In alternativa controlla dall'admin (KB tree) o via DB.

---

## 8. Test di chat per account (incl. isolamento)

Per ogni azienda, **login come l'utente dell'azienda** e fai una domanda la cui
risposta sta in una e-mail ingerita; verifica la risposta grounded + citazione.

Esempi (usa il "fatto-esca" come sonda):

| Azienda / utente | Domanda | Deve contenere | Non deve mai contenere |
|---|---|---|---|
| `rotta@case-study.local` | «Qual è il codice della spedizione Consegna Lampo 24h?» | `RL-2024-0815`, `VeloxCorriere` | `Protocollo Fenice-7`, `ClubPasso` |
| `prometeo@case-study.local` | «Qual è il protocollo del rinnovo CPI e la scadenza?» | `Protocollo Fenice-7`, `15/03/2029` | `RL-2024-0815`, `ClubPasso` |
| `passolibero@case-study.local` | «Qual è il modello recensito 5 stelle e l'ordine collegato?» | `ClubPasso Aero`, `#CLB-5521` | `Protocollo Fenice-7`, `VeloxCorriere` |

**Test di isolamento (cross-tenant/cross-project)**: ponendo a un account la
domanda di un'altra azienda, la risposta deve essere un rifiuto "nessun
contesto" — mai il fatto-esca dell'altra azienda. Se trapela, c'è una falla di
isolamento (R30) da investigare.

---

## 9. Troubleshooting

| Sintomo | Causa probabile | Rimedio |
|---|---|---|
| `mail:seed-imap` → "Env var ... non impostata" | App Password mancante in `.env` | Compila `CONNECTOR_TEST_<AZIENDA>_PASSWORD`. |
| APPEND fallisce con auth error | Password normale invece dell'App Password, o IMAP off | Usa l'App Password; abilita IMAP. |
| Le e-mail non vengono ingerite | Fuori finestra temporale | L'APPEND usa INTERNALDATE=now(); se hai forzato date vecchie alza `CONNECTOR_TEST_IMAP_DATE_WINDOW_DAYS`. |
| Doppioni di e-mail | Più `mail:seed-imap` senza `--purge`, o folders non limitati a INBOX | Usa `--purge`; il connettore è già limitato a `folders.include=[INBOX]`. |
| Ingest non parte | Coda non attiva | `QUEUE_CONNECTION=sync` o `php artisan queue:work`. |
| La chat non trova le e-mail | project_key senza membership (orfano) | Punta il connettore al project_key dell'azienda, o §6. |
| Embedding error in ingest | Provider AI non configurato | Imposta `AI_EMBEDDINGS_PROVIDER` + API key. |

---

## 10. Estendere ad altre aziende

Vedi il prompt agnostico
[`email-ingest-prompt.md`](email-ingest-prompt.md): individua le aziende
presenti con `demo:list-companies`, aggiunge l'account + le e-mail (con
fatto-esca) in `TestEmailFixtures`, le App Password in `.env`, e rigira i
comandi §3–§8.

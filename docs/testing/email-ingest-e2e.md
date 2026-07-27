# Dataset email case-study: generazione, delivery e ingest

Runbook operativo per il percorso:

```text
cataloghi versionati
  → generatore deterministico
  → manifest + shard JSONL
  → APPEND IMAP streaming e riprendibile
  → sync a tranche
  → ingest non canonico
  → verifica chat e isolamento
```

È un harness esclusivamente `local`/`testing`. Le credenziali
`CONNECTOR_TEST_*` devono restare vuote in produzione.

**Stato:** generazione, quality gate e hardening host sono completi offline.
Le sezioni IMAP, PostgreSQL/pgvector, retrieval, costo e performance descrivono
la certificazione live ancora da eseguire, non risultati già ottenuti.

## Contratto e profili

Le 751 email curate in `database/seeders/emails/*.json` restano il livello
`gold`. I cataloghi correnti sono immutabili sotto
`database/seeders/email-dataset/catalogs/v1/`; i profili sono in
`database/seeders/email-dataset/profiles/`. Gli output generati vanno in
`storage/app/demo-email-datasets/` e non vengono versionati nel repository.

| Profilo | Per mailbox | Per azienda | Totale | Uso |
|---|---:|---:|---:|---|
| `gold` | 119–136 | 242–262 | 751 | Regressione sul corpus curato |
| `demo` | 500 | 1.000 | 3.000 | Demo locale |
| `large` | 1.000 | 2.000 | 6.000 | Dataset completo consigliato |
| `stress` | 5.000 | 10.000 | 30.000 | Capacity test su IMAP locale |

Con seed `20260723`, catalogo `v1` e revisione generatore `g1`, gli artefatti
offline verificati sono:

| Profilo | Dataset version | Shard dati | SHA-256 aggregato dati |
|---|---|---:|---|
| `gold` | `case-study-email-v2-g1-gold-s20260723-catalogv1-snapede2f4abda9f7009` | 71 | `68cc51d53aa07f5f0c97f735bbf18a7b95b5696b3df5e0b9e3962b28fbeb1770` |
| `demo` | `case-study-email-v2-g1-demo-s20260723-catalogv1-snapa951e9cb2e6563c9` | 179 | `3455311d9e111070fc433990a98c9ef75ebe56d10c35a349819efbfc91ebb256` |
| `large` | `case-study-email-v2-g1-large-s20260723-catalogv1-snapa48c0f4751b501df` | 180 | `e4b3dc57d8a772d85523a86405f61d3bd600cc116b55814faea247669f7940d6` |
| `stress` | `case-study-email-v2-g1-stress-s20260723-catalogv1-snapfa1d1a0f4517df7c` | 180 | `cfa80dbad498353a243dec450519ab965e4f1a3aa08c1f10b7a439fb464ce677` |

| Profilo | Fingerprint snapshot completo | Shard indice | SHA-256 aggregato indice |
|---|---|---:|---|
| `gold` | `ede2f4abda9f700963d1a073439287e774165d2a9d3218299b2c10127953858c` | 240 | `526571bd589c36f9e6b91b591c7660615ad198136cc687621185f0952c0d90eb` |
| `demo` | `a951e9cb2e6563c9a5d364e159c73a28cdb52b97403a706ec70dc839300b0c19` | 256 | `aefc71efbb6ae456576eaac9d081fdc3c8934941a072b901098fb0f7af265b07` |
| `large` | `a48c0f4751b501dfc5e8e197daab04dc39cc358f994461b6295ef915d4d24ee9` | 256 | `c8a7a1a8b9b687dd6e664acb224f3da3c6a1c5efc9d4c0ce4c4dcf16664d1730` |
| `stress` | `fa1d1a0f4517df7c3c66f6464206a95edb33d003da1b75f61bd05938b5fd6169` | 256 | `6e9644437d66310874564c7eac9645dc4168569d727323152c189c35ad33b105` |

Il fingerprint è un content commitment SHA-256 su revisione generatore e
checksum, ordinati per path logico, di profilo, schema, indice catalogo,
registri aziendali e fixture gold selezionate. Le prime 16 cifre entrano nella
dataset version, il valore completo resta nel manifest. Gli shard e l’indice
fixture hanno checksum aggregati distinti.

## Aziende, tenant e mailbox

Ogni azienda è un tenant separato; `tenant_id` e `project_key` coincidono:

| Azienda | Tenant / progetto | Mailbox logiche |
|---|---|---|
| Rotta Sicura Logistics | `rotta-logistics` | `rotta-logistics-1`, `rotta-logistics-2` |
| Prometeo Sicurezza Antincendio | `prometeo-antincendio` | `prometeo-antincendio-1`, `prometeo-antincendio-2` |
| PassoLibero Calzature | `passolibero-calzature` | `passolibero-calzature-1`, `passolibero-calzature-2` |

Le sei mailbox possono essere sei label dello stesso account Gmail di test. Il
serializer usa l’identità fisica `host + port + username`, senza `tenant_id`,
quindi le connessioni allo stesso account vengono correttamente serializzate
anche tra tenant diversi.

## Prerequisiti

Per generare, validare e fare il preflight non servono rete, password o LLM.

Per la delivery IMAP servono:

- un account IMAP di test; per Gmail: 2FA, App Password e IMAP attivo;
- `CONNECTOR_TEST_GMAIL_PASSWORD` nel `.env`;
- nessuna config cache, perché le fixture dev/test leggono la password con
  `env()`;
- un server IMAP locale usa-e-getta per `stress`; non usare l’account Gmail
  condiviso per 30.000 messaggi.

Per il passaggio IMAP → KB servono inoltre:

- `KB_DISK_THROW=true`: il bridge rifiuta di avanzare il checkpoint UID se il
  disco KB non lancia errori sulle write fallite;
- `CASE_STUDY_EMAIL_DATASET_ROOT=storage/app/demo-email-datasets`, raggiungibile
  anche dai worker;
- `CASE_STUDY_EMAIL_REQUIRE_FIXTURE_INDEX=true` (default), così i metadata
  semantici vengono risolti dall'indice immutabile.

Per l’ingest servono inoltre:

- un worker di coda, oppure `QUEUE_CONNECTION=sync`;
- `AI_EMBEDDINGS_PROVIDER` e relativa chiave;
- PostgreSQL/pgvector per una certificazione realistica.

La timeline v2 va dal 2024-01-01 al 2026-06-30. Il fixture usa
`CONNECTOR_TEST_IMAP_DATE_WINDOW_DAYS=0` per non scartare gli shard storici.

## 1. Generazione

Genera il profilo `large`:

```bash
php artisan demo:generate-case-study-emails --profile=large --stats
```

Se la stessa versione esiste già, il comando si ferma. `--force` non autorizza
mai a cambiare i byte di una versione immutabile: rigenera in temporanea,
confronta SHA-256 di tutti i file e accetta soltanto un rerun byte-identico
(la temporanea viene scartata e l’artefatto pubblicato resta invariato):

```bash
php artisan demo:generate-case-study-emails --profile=large --force --stats
```

È possibile cambiare seed/catalogo o generare un subset:

```bash
php artisan demo:generate-case-study-emails \
  --profile=demo \
  --seed=20260723 \
  --catalog-version=v1 \
  --company=rotta-logistics \
  --mailbox=rotta-logistics-1 \
  --stats
```

Un subset riceve una dataset version distinta. Un cambiamento di input produce
un nuovo fingerprint/versione; un cambiamento del codice generativo richiede il
bump di `GENERATOR_REVISION` (oppure una nuova versione di catalogo). Il
generatore rilegge gli input dopo il commitment e ricalcola il fingerprint
prima del publish: se cambiano durante il run, scarta la temporanea. Non usa LLM
né rete e pubblica solo dopo schema, checksum e quality gate.

## 2. Determinismo e quality gate

Verifica che gli stessi input rigenerino esattamente gli stessi byte:

```bash
php artisan demo:generate-case-study-emails --profile=large --check --stats
```

Valida un artefatto già pubblicato senza rigenerarlo:

```bash
php artisan demo:validate-case-study-emails --profile=large
```

Oppure usa una versione esplicita:

```bash
php artisan demo:validate-case-study-emails \
  --dataset-version=case-study-email-v2-g1-large-s20260723-catalogv1-snapa48c0f4751b501df
```

La validazione è strict per default e controlla:

- checksum di ogni shard e checksum aggregato;
- schema v2 e campi supportati;
- indirizzi esclusivamente su domini riservati;
- unicità di `fixture_id`, `Message-ID` e contenuto generato;
- thread di 2/3/4/5/8 messaggi con catena `In-Reply-To`/`References`
  contigua e date crescenti;
- correzioni da `incorrect` a `corrected`;
- assenza di header di thread sui messaggi standalone;
- proprietà degli ID-canary e assenza letterale di frasi-canary delle altre
  aziende, anche quando il record non dichiara l'ID;
- coerenza di mailbox, scenario, mittente, fatti e fonti canoniche con
  `catalogs/v1`;
- contratto degli eventuali attachment.

Le identità e i thread corpus-sized sono indicizzati in un SQLite temporaneo:
la memoria PHP resta bounded al thread/counter mentre la parte `O(corpus)` è su
disco. Il catalogo `v1` genera solo text/plain e `attachments=[]`; il validator
non è uno scanner semantico di PII e non calcola similarità near-duplicate.

## 3. Preflight senza rete

Prima di ogni delivery:

```bash
php artisan mail:seed-imap \
  --all \
  --profile=large \
  --summary-only \
  --estimate-cost
```

Il preflight legge e valida tutti i 6.000 record e stampa il numero di documenti
parent attesi. Non invia messaggi e non legge la password. Nonostante il nome
storico dell'opzione, non calcola un prezzo: token, costo embedding, spazio DB e
durata devono essere misurati durante la certificazione live.

Il profilo `stress` contiene 30.000 messaggi ed è riservato a un server IMAP
locale usa-e-getta. Non usarlo sull'account Gmail condiviso: gli APPEND sono
seriali sul singolo account fisico, possono richiedere molte ore e subire
throttling. Il comando stampa un warning esplicito quando rileva `stress` su un
host remoto; per la certificazione Gmail usare `large`.

Per le fixture generate:

- generazione e delivery: zero chiamate LLM;
- `AnalyzeDocumentChangeJob` e `AutoWikiCompilerJob`: zero, perché solo il
  namespace Message-ID riservato
  `@fixtures.askmydocs.invalid` imposta `metadata.generated_fixture=true`;
- embedding: dipendono dal provider e dal numero finale di chunk.

Le fixture legacy usano un dominio Message-ID separato e continuano a seguire
la pipeline AI normale. Un header o metadata spoofato non può attivare il gate.

## 4. Delivery IMAP streaming

Primo caricamento o reinstallazione pulita della sola versione `large`:

```bash
php artisan mail:seed-imap \
  --all \
  --profile=large \
  --purge-dataset \
  --summary-only
```

Selezioni supportate:

```bash
php artisan mail:seed-imap --project=rotta-logistics --profile=large --purge-dataset
php artisan mail:seed-imap --mailbox=rotta-logistics-1 --profile=large --purge-dataset
```

La delivery:

- legge un record JSONL e costruisce un RFC822 alla volta;
- acquisisce il lock della mailbox fisica, condiviso con le altre superfici
  IMAP, lo rinnova in modo owner-safe e usa una sola connessione;
- conserva Message-ID, header `Date` storico e thread reali;
- usa l'ora corrente come INTERNALDATE remoto, perché un sync incrementale
  deve vedere anche una fixture dalla timeline narrativa 2024–2026;
- invia text/plain per il catalogo `v1` (HTML e attachment sono supportati dal
  contratto ma non sono generati);
- salva un checkpoint atomico ogni `--batch-size` messaggi, default 100;
- su drop ambiguo cerca il singolo `Message-ID` prima di ritentare;
- fa purge server-side a blocchi di 100, marcando l'intero blocco prima di un
  solo `UID EXPUNGE` selettivo; il server deve dichiarare `UIDPLUS`, altrimenti
  il comando fallisce prima di marcare messaggi come `\Deleted`;
- stampa attesa/acquisizione lock, inizio/fine purge e APPEND confermati con
  throughput ed ETA; `-v` aggiunge il subject di ogni messaggio preparato,
  mentre `--progress-every=N` regola i riepiloghi degli ACK.

Con `CONNECTOR_IMAP_SERIALIZE_CONNECTIONS=true`, il runtime CLI deve offrire
PCNTL/SIGALRM. La lease viene rinnovata prima di ogni APPEND/purge e dopo ogni
ACK; `CONNECTOR_IMAP_SEED_LOCK_TTL` vale 14.400 secondi per default e
`CONNECTOR_IMAP_SEED_LOCK_SAFETY_MARGIN` 30 secondi (minimo 2). Una guardia
wall-clock interrompe I/O bloccante prima del margine e ripristina sempre
handler e alarm precedenti. Se refresh/ownership falliscono, nessun nuovo
APPEND parte e l’ACK ambiguo non avanza il checkpoint. Il purge usa la stessa
guardia e il checkpoint viene cancellato solo dopo una nuova verifica owner-safe.

`--purge-dataset` elimina solo i messaggi con
`X-AskMyDocs-Dataset-Version=<versione>`, azzera il checkpoint corrispondente e
poi riappende il dataset. Aggiungere `--purge-only` per eseguire soltanto la
rimozione senza riappendere.
`--purge-all-seeded` e il suo alias legacy `--purge` cancellano invece tutte le
fixture della mailbox: usarli solo quando si vuole davvero rimuovere anche
gold e altre versioni.

Prima della cancellazione remota viene scritto atomicamente, nella directory
dei checkpoint, un marker
`<physical_mailbox_hash>.purge-intent.json` (SHA-256 di host, porta, account e
folder). Se il processo cade dopo
il purge ma prima del reset locale, il successivo run mutante — anche un plain
`--resume` senza ripetere il flag purge — recupera il marker sotto lease,
riesegue lo stesso purge idempotente, elimina il checkpoint mirato (o tutti per
il purge ampio) e solo allora rimuove il marker. Un checkpoint completo stale
non può quindi trasformare il resume in un falso no-op.

## 5. Stop e resume

Per fermare in sicurezza, interrompere il comando e non cancellare la directory
dataset né `storage/app/email-seed-checkpoints/`: contiene sia i checkpoint sia
gli eventuali purge-intent necessari al recovery. Poi ripartire con lo stesso
manifest:

`SIGINT` (`Ctrl-C`) e `SIGTERM` vengono convertiti in un arresto gestito: gli
handler precedenti sono ripristinati e il lock fisico viene rilasciato dal
`finally`. Solo un `SIGKILL`, un crash runtime o lo spegnimento della macchina
possono lasciare il lock fino alla scadenza del TTL.

```bash
php artisan mail:seed-imap \
  --all \
  --profile=large \
  --resume \
  --summary-only
```

Il resume rifiuta un manifest con checksum diverso. Dopo un arresto duro
ricontrolla al massimo l’ultimo intervallo di checkpoint per `Message-ID`, così
un APPEND già accettato non viene duplicato. Un checkpoint completo rende i
run successivi no-op.

Una scadenza/perdita del lock è un errore esplicito e riprendibile: non alzare
il TTL come rimedio. Correggere il lock store/PCNTL o la connettività e usare
`--resume`; il checkpoint resta fermo all’ultimo ACK confermato sotto ownership.

Se si vuole ricominciare da zero usare `--purge-dataset`, non cancellare a mano
il solo checkpoint lasciando i messaggi remoti. Se invece si vuole soltanto
rimuovere la versione, usare insieme `--purge-dataset --purge-only`.

## 6. Installazione connettori e sync

Prima inizializzazione:

```bash
php artisan db:seed --class=Database\\Seeders\\RbacSeeder
php artisan db:seed --class=Database\\Seeders\\CaseStudyUsersSeeder
php artisan connector:imap:install --all --sync
```

Ogni mailbox diventa un’installazione nel tenant della propria azienda. Un rerun
riconfigura la riga in place e conserva `installation_id`, config e secret
precedenti se il nuovo ping fallisce.

Le sei installazioni possono condividere lo stesso account fisico. Il layer di
connessione mantiene una sola sessione IMAP per account; i job concorrenti
vengono riaccodati senza marcare l’installazione come errored.

Il connettore upstream limita un run a 5.000 messaggi. Il job host salva il
prefisso UID contiguo già consegnato all’ingest e, quando il run è troncato,
mantiene il vecchio `last_sync_at`. Il run successivo riprende dopo l’ultimo UID
sicuro invece di saltare le email storiche. Un attachment fallito blocca il
watermark prima del relativo UID.

`--sync` accoda i job ma non attende il drenaggio. Tenere attivi i worker e
controllare `connector_sync_runs`, code e failed jobs.

## 7. Orchestratore one-shot

Generazione, preflight, delivery, installazione e sync:

```bash
php artisan demo:init-case-studies \
  --profile=large \
  --generate-email-dataset \
  --ingest-emails
```

Ripresa dopo un’interruzione:

```bash
php artisan demo:init-case-studies \
  --profile=large \
  --resume \
  --ingest-emails
```

Senza `--profile`/`--dataset-version` resta disponibile il comportamento legacy
sulle 751 fixture curate. Ogni sotto-comando propaga il proprio exit code; il
comando non dichiara completato l’ingest, ma avvisa che i sync sono in coda.

## 8. Verifica ingest e isolamento

Controlla tenant, progetti, documenti, chunk e connettori:

```bash
php artisan demo:list-companies
```

Per ogni documento email v2 sono attesi:

- `tenant_id` uguale all’azienda;
- `project_key` uguale all’azienda;
- `metadata.generated_fixture === true`;
- `metadata.dataset_version` e `metadata.fixture_id` derivati dal Message-ID;
- `company_key`, `mailbox_key`, `scenario_type`, `topic`, `message_type`,
  `thread_id`, `fact_ids`, `canonical_sources`, `truth_state` e `canary_ids`
  risolti dall'indice fixture checksum-verificato;
- documento non canonico;
- nessun job Auto-Wiki o change-analysis.

Il source parent non resta legato all'UID remoto. Il bridge lo pubblica su:

```text
<project>/connectors/imap/installation-<id>/<folder>/
datasets/<dataset_version>/<fixture_id>.md
```

Un purge/re-APPEND che assegna nuovi UID mantiene quindi la stessa famiglia
documentale. Se il rollback aveva soft-deleted quella proiezione esatta, una
riconsegna della stessa versione la ripristina prima dell'ingest idempotente.

Poi esegui la matrice di isolamento in ciascun tenant:

```bash
php artisan case-study:verify-isolation \
  --tenant=rotta-logistics \
  --project=rotta-logistics
php artisan case-study:verify-isolation \
  --tenant=prometeo-antincendio \
  --project=prometeo-antincendio
php artisan case-study:verify-isolation \
  --tenant=passolibero-calzature \
  --project=passolibero-calzature
```

I canarini di riferimento restano:

- Rotta: `RL-2024-0815`, `VeloxCorriere`;
- Prometeo: `Protocollo Fenice-7`;
- PassoLibero: `ClubPasso Aero`, `#CLB-5521`.

Una query nel tenant sbagliato deve rifiutare o rispondere senza canarini,
documenti o citazioni dell’altra azienda.

## 9. Rollback per dataset version

Prima fermare nuovi sync e lasciare terminare i job già in esecuzione. Poi:

1. annotare la dataset version e i conteggi dal manifest;
2. rimuovere da IMAP soltanto quella versione:

   ```bash
   php artisan mail:seed-imap \
     --all \
     --dataset-version=case-study-email-v2-g1-large-s20260723-catalogv1-snapa48c0f4751b501df \
     --purge-dataset \
     --purge-only \
     --summary-only
   ```

3. soft-delete, tenant per tenant, solo i documenti con
   `metadata.dataset_version` esattamente uguale:

   ```bash
   docs/case-studies/teardown.sh \
     --tenant=rotta-logistics \
     --project=rotta-logistics \
     --dataset-version=case-study-email-v2-g1-large-s20260723-catalogv1-snapa48c0f4751b501df
   ```

   Ripetere con la coppia tenant/progetto di Prometeo e PassoLibero. Lo script
   usa `DocumentDeleter::delete(force: false)` e `chunkById(100)`;
4. verificare che le 751 fixture gold, i documenti canonici e gli altri tenant
   siano invariati;
5. conservare il manifest per audit e riproducibilità.

Non usare wildcard, tenant `default`, hard delete o `--purge-all-seeded` per un
rollback di versione.

## Troubleshooting

| Sintomo | Causa | Azione |
|---|---|---|
| `Dataset already exists` | stessa versione già pubblicata | `--check` per verificare; `--force` accetta soltanto byte identici, mai una sostituzione diversa |
| `Checksum mismatch` | shard modificato/corrotto | non consegnare; rigenerare dalla stessa tripletta profilo/seed/catalogo |
| `manifest ... è cambiato` in resume | versione riusata con byte diversi | ripristinare il manifest originale o usare una nuova dataset version |
| password assente nonostante `.env` | config cache attiva | `php artisan config:clear` |
| auth IMAP fallita | password normale/IMAP disattivo | usare App Password e abilitare IMAP |
| checkpoint presente | run precedente esistente | `--resume` oppure `--purge-dataset` per pulire e riappendere |
| `.purge-intent.json` presente | crash durante purge/clear checkpoint | non cancellarlo; rilanciare `--resume`, che ripete il purge idempotente e completa il recovery |
| PCNTL/SIGALRM assente o lease persa | il seed serializzato non può garantire che I/O finisca sotto lock | usare PHP CLI con PCNTL e lock atomico; poi `--resume`, senza cancellare il checkpoint |
| devo solo rimuovere una versione | `--purge-dataset` da solo riappenderebbe | aggiungere `--purge-only` |
| `IMAP ingestion requires ... throw=true` | disco KB non fail-fast | impostare `KB_DISK_THROW=true` e svuotare la config cache |
| indice fixture assente/corrotto | artefatto generato da un contratto vecchio o modificato | rigenerare con il codice corrente; non disabilitare il gate in produzione |
| email storiche mancanti | finestra temporale diversa da zero | impostare `CONNECTOR_TEST_IMAP_DATE_WINDOW_DAYS=0` e riconfigurare |
| sync fermo a 5.000 | worker non ha eseguito la tranche successiva | mantenere attivi i worker e controllare progress/errori |
| ingest non parte | coda ferma | avviare `php artisan queue:work` |
| embedding fallisce | provider/chiave assenti | configurare `AI_EMBEDDINGS_PROVIDER` |

## Test automatici rilevanti

```bash
php artisan test \
  tests/Unit/Services/Demo/EmailDataset \
  tests/Unit/Services/Demo/EmailSeedCheckpointStoreTest.php \
  tests/Unit/Services/Demo/EmailSeedLockLeaseTest.php \
  tests/Unit/Services/Demo/EmailMessageBuilderTest.php \
  tests/Feature/Console/GenerateCaseStudyEmailsCommandTest.php \
  tests/Feature/Console/ValidateCaseStudyEmailsCommandTest.php \
  tests/Feature/Console/MailSeedImapCommandTest.php \
  tests/Feature/Console/InitCaseStudiesCommandTest.php \
  tests/Feature/Console/ConnectorImapInstallCommandTest.php \
  tests/Feature/Connectors/ImapSyncProgressTest.php \
  tests/Feature/Connectors/HostIngestionBridgeTest.php \
  tests/Feature/Jobs/IngestDocumentJobTest.php
```

I test automatici coprono determinismo, indice fixture, corruzione,
resume/fault injection con cap ridotto, recovery da crash post-purge tramite
intent durevole, reinstallazione in place, path KB stabile, restore soft-delete,
isolamento tenant del checkpoint e gate AI. Non
coprono un E2E IMAP reale da 5.001+, scanner PII, near-duplicate, costi misurati
o retrieval sul corpus `large`. La certificazione su IMAP, Gmail,
PostgreSQL/pgvector e provider di embedding rimane manuale perché richiede
infrastruttura e credenziali esterne.

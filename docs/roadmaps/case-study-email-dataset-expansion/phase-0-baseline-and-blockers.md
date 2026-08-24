# Fase 0 — Baseline, decisioni e blocker

## Obiettivo

Congelare i 751 messaggi curati e rendere sicura la moltiplicazione del corpus.

## Baseline

| Mailbox | Email |
|---|---:|
| `rotta-logistics-1` | 136 |
| `rotta-logistics-2` | 126 |
| `prometeo-antincendio-1` | 128 |
| `prometeo-antincendio-2` | 119 |
| `passolibero-calzature-1` | 122 |
| `passolibero-calzature-2` | 120 |
| **Totale** | **751** |

I sei JSON raw restano invariati. L'adapter li porta nel contratto compilato
`schema_version=2.0` con identità deterministiche, ma non applica un overlay
editoriale di thread o verità: i record gold sono standalone e `current`.

## Blocker e soluzione implementata

### B0.1 — Identità IMAP e KB

Problema: UID e source path del connettore cambiano dopo purge/re-APPEND.

Soluzione:

- `fixture_id`, `dataset_version` e Message-ID deterministici;
- reinstallazione del connettore in place, con installation ID preservato;
- path parent stabile:
  `.../installation-<id>/<folder>/datasets/<dataset_version>/<fixture_id>.md`;
- `imap_doc_key` logica `fixture:<dataset_version>:<fixture_id>`;
- lookup tenant-scoped della proiezione soft-deleted e `restore()` prima del
  normale ingest idempotente.

La stabilità è rispetto al cambio di UID della stessa installazione. Una
ricreazione distruttiva dell'installazione resta fuori dal percorso ordinario.

### B0.2 — Memoria

Soluzione:

- shard JSONL letti riga per riga;
- un RFC822 costruito e inviato alla volta;
- purge remoto a blocchi;
- quality gate globale indicizzato in SQLite temporaneo;
- PHP conserva solo counter bounded e un thread, lungo al massimo 8 messaggi.

La memoria non contiene un set PHP con tutte le fixture o tutti i thread.

### B0.3 — Truncation del sync

Il decorator host registra solo il prefisso contiguo di UID per cui filtro,
parent e attachment accettati hanno completato il percorso host. Su truncation
il job ripristina il precedente `last_sync_at`, così una tranche successiva può
riprendere.

La logica è coperta da test con cap ridotto e fault injection. Una mailbox IMAP
reale con 5.001+ messaggi non è ancora stata certificata.

### B0.4 — Scritture e fallimenti parziali

- l'orchestratore propaga gli exit code;
- pubblicazione dataset e checkpoint sono atomici;
- il bridge host richiede che il disco usato dal connettore abbia
  `throw=true` (`KB_DISK_THROW=true`);
- prima del dispatch verifica che il source esista;
- copia e cancellazione dal path UID al path stabile sono controllate;
- il progresso UID avanza soltanto dopo il dispatch host riuscito.

Questo hardening compensa il fatto che il package IMAP attuale non usa il
boolean di `Storage::put()` come contratto applicativo.

### B0.5 — Tenant e rollback

Ogni mailbox usa:

```text
tenant_id = project_key = company_key
```

Il rollback DB richiede sempre
`tenant_id + project_key + dataset_version`. Il purge IMAP di sola rimozione è:

```bash
php artisan mail:seed-imap \
  --all \
  --dataset-version=<versione> \
  --purge-dataset \
  --purge-only \
  --summary-only \
  --actor=operator:rollback \
  --preview-purge
```

Copiare il token e ripetere gli stessi argomenti sostituendo
`--preview-purge` con `--confirm-token=<token>`. `--purge-dataset` senza
`--purge-only` elimina e poi riappende la versione.

### B0.6 — Chiamate AI

Il bridge rimuove metadata fixture non fidati e riconosce una fixture generata
soltanto dal Message-ID:

```text
<dataset-version.fixture-id@fixtures.askmydocs.invalid>
```

Dopo la verifica, `IngestDocumentJob` salta change analysis e Auto-Wiki, ma
continua il normale ingest non canonico e gli embedding.

## Bonifica contenutistica: stato

Sono implementati schema e classificazione per i record generati. Non sono
implementati:

- mapping manuale delle reply apparenti del gold in thread RFC822;
- revisione editoriale dei 751 truth state;
- attachment sintetici per le menzioni storiche “in allegato”.

Questi punti non bloccano il volume offline: descrivono l'arricchimento
editoriale ancora possibile del livello gold.

## Checklist

- [x] Baseline gold compilata in schema v2.
- [x] Identità e path stabili rispetto all'UID.
- [x] Restore della proiezione soft-deleted.
- [x] Reader, validator, append e purge bounded.
- [x] Lock cross-tenant sulla mailbox fisica.
- [x] Checkpoint UID contiguo con fault test.
- [x] Orchestratore fail-fast.
- [x] Tenant e teardown espliciti.
- [x] Gate AI autenticato dal Message-ID.
- [ ] Rerun certificato su IMAP reale e PostgreSQL.
- [ ] Test live con mailbox oltre 5.000 messaggi.

## Criterio di uscita

Il core offline è chiuso. La fase sarà certificata live quando seed, sync,
rollback e seed identico lasceranno invariati i conteggi PostgreSQL su un server
IMAP reale.

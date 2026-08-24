# Fase 4 — Ingestione, capacità e controllo costi

## Obiettivo

Portare le fixture da IMAP alla KB senza perdita di identità, duplicati da UID,
contaminazione tenant o chiamate chat inattese.

## Carico dichiarato

`large` contiene 6.000 messaggi parent, 1.000 per installazione. Il catalogo
`v1` non genera attachment, quindi il conteggio atteso è 6.000 parent e zero
documenti attachment.

`stress` contiene 30.000 parent, 5.000 per installazione. È stato generato e
validato offline; delivery e ingest non sono stati certificati.

## Metadata end-to-end

Il Message-ID autentica `dataset_version + fixture_id`. Il bridge recupera
dall'indice fixture checksum-verificato:

- `company_key`;
- `mailbox_key`;
- `scenario_type`;
- `topic`;
- `message_type`;
- `thread_id`;
- `fact_ids`;
- `canonical_sources`;
- `truth_state`;
- `canary_ids`.

Questi valori si aggiungono ai metadata IMAP nativi, tra cui installation ID,
mailbox, UID, UIDVALIDITY e Message-ID. Per default l'indice è obbligatorio:

```env
CASE_STUDY_EMAIL_DATASET_ROOT=storage/app/demo-email-datasets
CASE_STUDY_EMAIL_REQUIRE_FIXTURE_INDEX=true
```

Una fixture il cui `company_key` non coincide con il progetto viene rifiutata.

## Path KB stabile e restore

Il package IMAP scrive inizialmente un source path basato su UID. Prima del
dispatch il bridge:

1. richiede che il disco risolto abbia `throw=true`;
2. verifica l'esistenza del source;
3. pubblica il parent su:

   ```text
   <project>/connectors/imap/installation-<id>/<folder>/
   datasets/<dataset_version>/<fixture_id>.md
   ```

4. rimuove il file UID transitorio con esito controllato;
5. imposta una chiave logica
   `fixture:<dataset_version>:<fixture_id>`.

`KB_DISK_THROW=true` è quindi un requisito operativo, non un'opzione
consigliata.

Se un rollback ha soft-deleted esattamente la stessa proiezione tenant/progetto,
dataset version e fixture ID, il bridge la ripristina prima di dispatchare
l'ingest idempotente. Non ripristina righe di altri tenant o versioni.

## Canonical awareness e gate AI

Le fixture email:

- restano `is_canonical=false`;
- non ricevono frontmatter o promozione automatica;
- seguono il normale chunking/embedding;
- saltano change analysis e Auto-Wiki soltanto se il Message-ID appartiene al
  namespace riservato.

Un metadata/header spoofato senza Message-ID valido non attiva il gate.

## Sync contiguo

Il progresso host conferma un UID dopo filtro o dispatch riusciti. Un parent o
attachment fallito blocca il watermark; UID successivi possono essere rigiocati,
ma non saltano quello fallito. Su truncation il vecchio `last_sync_at` viene
ripristinato.

Questa logica è coperta offline. La certificazione su una mailbox reale oltre
il cap è pending.

## Preflight

```bash
php artisan mail:seed-imap \
  --all \
  --profile=large \
  --summary-only \
  --estimate-cost
```

Il comando:

- valida manifest e record;
- non usa rete, DB o password;
- stampa il numero esatto di parent attesi;
- dichiara zero chiamate chat per le fixture v2.

Il nome dell'opzione è storico: non calcola token, prezzo embedding, spazio DB
o durata. Questi valori dipendono dal provider e devono essere misurati live.

## Osservabilità disponibile e mancante

Sono disponibili le superfici generali del prodotto:

- `connector_sync_runs`;
- stato installazioni;
- code e `failed_jobs`;
- log dei worker;
- FinOps quando il provider e il metering sono attivi.

Non è implementato un run report persistente dedicato al dataset che correli
automaticamente expected/APPEND/sync/ingest/costo per `dataset_version`.

## Checklist

- [x] Metadata semantici da indice fixture.
- [x] Verifica owner progetto.
- [x] `KB_DISK_THROW=true` enforced sul percorso IMAP.
- [x] Path stabile per dataset version e fixture ID.
- [x] Restore della proiezione soft-deleted.
- [x] Tenant scope esplicito.
- [x] Fixture non canoniche.
- [x] Gate Auto-Wiki/change analysis.
- [x] Sync con watermark contiguo.
- [x] Preflight conteggi senza rete.
- [ ] Ingest `large` su PostgreSQL/pgvector.
- [ ] Rerun live con conteggi invariati.
- [ ] Costi, token, throughput e RSS misurati.
- [ ] Run report dataset-specific.

## Criterio di uscita

Il core host è completo. La fase live si chiude con 6.000 parent su
PostgreSQL/pgvector, un rerun invariato, zero contaminazioni e misure reali di
tempo/costo.

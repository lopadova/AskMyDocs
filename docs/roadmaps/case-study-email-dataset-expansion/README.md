# Espansione del dataset email dei case study

- **Stato:** core offline e hardening sicurezza completi; certificazione live bloccata
- **Data:** 2026-07-28
- **ready_for_implementation:** `true`
- **Ambito:** Rotta Sicura Logistics, Prometeo Sicurezza Antincendio,
  PassoLibero Calzature

## Risultato

Le 751 email curate restano il livello `gold`. Il generatore deterministico
compila quattro profili:

| Profilo | Per mailbox | Per azienda | Totale | Uso |
|---|---:|---:|---:|---|
| `gold` | 119–136 | 242–262 | 751 | Regressione sul corpus curato |
| `demo` | 500 | 1.000 | 3.000 | Demo locale |
| `large` | 1.000 | 2.000 | 6.000 | Target completo consigliato |
| `stress` | 5.000 | 10.000 | 30.000 | Generazione/capacity offline |

Il profilo `large` contiene i 751 record gold normalizzati e 5.249 record
generati. Non aggiunge 6.000 messaggi ai 751 esistenti.

Gli artefatti correnti usano revisione generatore `g1`. La loro identità
include un content commitment `snap<16-hex>` derivato dal fingerprint SHA-256
completo degli input; versioni e checksum verificati sono riportati in
[`IMPLEMENTATION-STATUS.md`](IMPLEMENTATION-STATUS.md).

## Architettura implementata

1. I JSON storici restano in
   [`database/seeders/emails/`](../../../database/seeders/emails/).
2. Gli input editoriali sono versionati sotto
   [`database/seeders/email-dataset/catalogs/v1/`](../../../database/seeders/email-dataset/catalogs/v1/);
   i profili di volume restano sotto
   [`database/seeders/email-dataset/profiles/`](../../../database/seeders/email-dataset/profiles/).
3. `demo:generate-case-study-emails` genera offline shard JSONL e manifest
   atomici da `profile + seed + catalog_version`.
4. `demo:validate-case-study-emails` verifica checksum, contratto, identità,
   cataloghi, thread e canarini. Le identità e i thread corpus-sized sono
   indicizzati in un SQLite temporaneo, così la memoria PHP resta bounded.
5. Il writer usa un budget LRU condiviso di 64 file descriptor per shard dati
   e indice, senza cambiare i byte. `mail:seed-imap` esegue APPEND one-by-one
   sotto una lease rinnovabile, owner-safe e condivisa con il sync. Una guardia
   PCNTL interrompe I/O prima del TTL; checkpoint e `--resume` riconciliano gli
   ACK ambigui.
6. APPEND e purge reali sono ammessi soltanto in `local/testing`. Ogni purge
   richiede preview e token DB monouso legato a scope/checksum/attore; ogni
   mailbox apre un audit tenant-scoped prima dell'I/O remoto.
7. Il connettore conserva un watermark UID contiguo. Le fixture generate
   arrivano in KB come documenti non canonici; il Message-ID riservato è il
   marker necessario per escludere Auto-Wiki e change analysis.
8. Il bridge host verifica la persistenza sul disco KB, richiede
   `KB_DISK_THROW=true`, sposta il parent su un path stabile che include
   `dataset_version + fixture_id` e ripristina la stessa proiezione se era stata
   soft-deleted.

La generazione non usa LLM, rete o credenziali. Un LLM può essere usato soltanto
in un workflow editoriale separato e con review umana prima di creare una nuova
versione dei cataloghi.

## Baseline

| Azienda | Mailbox 1 | Mailbox 2 | Gold | `large` |
|---|---:|---:|---:|---:|
| Rotta Sicura Logistics | 136 | 126 | 262 | 2.000 |
| Prometeo Sicurezza Antincendio | 128 | 119 | 247 | 2.000 |
| PassoLibero Calzature | 122 | 120 | 242 | 2.000 |
| **Totale** | **386** | **365** | **751** | **6.000** |

## Flusso

```text
catalogs/<version> + profiles
              │
              ▼
generatore deterministico
              │
              ├── manifest + shard JSONL
              └── quality gate SQLite bounded
                              │
                              ▼
                    APPEND IMAP + checkpoint
                              │
                              ▼
                   sync UID contiguo + ingest
                              │
                              ▼
                  KB non canonica, path stabile
```

## Fasi e stato reale

| Fase | Documento | Stato |
|---|---|---|
| 0 | [Baseline e blocker](phase-0-baseline-and-blockers.md) | Hardening offline completato; live non certificato |
| 1 | [Modello dei contenuti](phase-1-content-model.md) | Schema e registri `v1` implementati |
| 2 | [Generatore](phase-2-deterministic-generator.md) | Completa e validata offline |
| 3 | [Delivery IMAP](phase-3-streaming-imap-delivery.md) | Contratto e fault test completi; E2E IMAP live pending |
| 4 | [Ingestione e capacità](phase-4-ingestion-capacity-and-cost.md) | Guard host completi; PostgreSQL/costi reali pending |
| 5 | [Qualità e RAG](phase-5-quality-and-evaluation.md) | Quality gate statico completo; retrieval live pending |
| 6 | [Rollout](phase-6-rollout-and-operations.md) | Runbook completo; rollout live pending |

Il dettaglio verificato è in
[`IMPLEMENTATION-STATUS.md`](IMPLEMENTATION-STATUS.md).

## Invarianti

- `tenant_id = project_key = company_key`; nessuna fixture case-study nel
  tenant `default`.
- Le email sono non canoniche. Il markdown canonico resta la fonte di verità.
- `fixture_id`, `Message-ID` e `dataset_version` sono deterministici.
- La dataset version contiene revisione generatore e prefisso del fingerprint;
  il manifest conserva il commitment completo sugli input.
- `--force` non sovrascrive mai una versione con byte diversi: accetta soltanto
  una rigenerazione byte-identica. Per cambiare contenuto servono un nuovo
  fingerprint e, quando cambia l’algoritmo, un bump di revisione.
- Un rerun con la stessa installazione e versione non crea una nuova famiglia
  documentale al variare dell'UID IMAP.
- Il quality gate e la delivery usano memoria bounded; i dati globali del
  validator vivono su SQLite temporaneo e il writer non supera 64 handle
  condivisi fra dati e indice.
- Gli indirizzi usano domini riservati e `sensitivity=synthetic_non_real`.
- Il validator blocca frasi-canary di un'altra azienda anche se il record non
  dichiara il relativo `canary_id`.
- `--purge-dataset` elimina la versione e poi la riappende;
  `--purge-dataset --purge-only` esegue soltanto la rimozione.
- Un purge scrive prima un intent atomico per mailbox fisica; un run successivo
  lo recupera sotto lease prima di usare checkpoint eventualmente stale.
- Il token di purge è monouso, hash-only e consumato con lock DB dopo il
  preflight deterministico ma prima del primo delete remoto.
- Audit APPEND/purge e rifiuti sono separati per mailbox e tenant, senza
  credenziali o contenuto e-mail.

## Limiti dichiarati

La versione `v1` contiene per azienda 6 personas, 9 fatti, 9 scenari e 4
canarini. Il corpus generato attuale è text/plain, con CC opzionale, ma non
produce HTML o allegati; lo schema e il builder li supportano soltanto come
contratto futuro.

Il quality gate non è un rilevatore semantico di PII e non calcola similarità
near-duplicate. Verifica invece domini riservati, campo sensitivity, duplicati
esatti dei record generati e isolamento dei canarini.

È disponibile il lifecycle forense in `admin_command_audit`, ma non un report
aggregato unico per rollout. Costi embedding misurati, benchmark live da 5.001+
messaggi nella stessa mailbox e metriche retrieval/performance sul corpus
`large` restano bloccati. Vedi
[`CERTIFICATION-2026-07-28.md`](CERTIFICATION-2026-07-28.md).

## Definition of Done

### Core offline — completato

- conteggi esatti per tutti i profili;
- byte-determinismo a parità di input;
- pubblicazione e checkpoint atomici;
- APPEND streaming e resume testati con fault simulati;
- refresh owner-safe, hard deadline e perdita lease testati, incluso un ACK
  oltre TTL che non avanza il checkpoint e non viene duplicato al resume;
- crash post-purge recuperato da intent durevole prima del checkpoint;
- path KB stabile e restore della proiezione soft-deleted;
- gate Auto-Wiki/change-analysis autenticato dal Message-ID;
- quality gate bounded e isolamento statico dei canarini;
- writer verificato byte-identico con budget di 2 e 64 handle;
- environment gate senza override, token purge monouso e audit per mailbox;
- runbook con stop, resume, purge-only e rollback tenant-scoped.

### Certificazione esterna — pending

- APPEND/resume/purge su IMAP reale;
- ingest completo dei 6.000 parent su PostgreSQL/pgvector;
- rerun live con conteggi DB invariati;
- retrieval e precedenza canonica sulle tre aziende;
- costi, throughput, RSS e p95 misurati;
- test reale oltre il cap e capacity del profilo `stress`;
- campione di compatibilità Gmail.

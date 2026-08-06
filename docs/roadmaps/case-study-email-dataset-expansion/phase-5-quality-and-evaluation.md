# Fase 5 — Qualità, integrazione e valutazione RAG

## Obiettivo

Separare il quality gate offline già dimostrato dalla valutazione live di
ingest e retrieval ancora da eseguire.

## Quality gate implementato

```bash
php artisan demo:validate-case-study-emails \
  --dataset-version=<versione>
```

Il comando verifica:

- identità dataset coerente tra richiesta, manifest e record;
- checksum degli shard email e dell'indice fixture;
- conteggi per azienda, mailbox, tipo messaggio e truth state;
- contratto record, date, header e domini riservati;
- `fixture_id` e Message-ID unici;
- subject+body unici tra i record generati;
- mittente, mailbox, scenario, fatti e fonti canoniche presenti nei cataloghi;
- catene thread contigue, cronologiche e confinate a
  azienda/mailbox/scenario;
- transizione `incorrect` → `corrected` nei thread di correzione;
- canary ID appartenenti all'azienda;
- assenza letterale di frasi-canary di un'altra azienda, anche se il record non
  dichiara il relativo ID.

## Memoria del validator

Le identità e i thread corpus-sized sono salvati in un database SQLite
temporaneo con indici unici. PHP mantiene:

- cataloghi/counter a cardinalità bounded;
- una riga alla volta durante la scansione;
- un thread alla volta, massimo 8 messaggi.

La memoria applicativa è quindi bounded dal batch/contratto, mentre la parte
`O(corpus)` è su disco e viene rimossa a fine validazione.

## Cosa il gate non verifica

- PII semantica o dato personale reale nel testo;
- similarità near-duplicate;
- qualità stilistica o realismo linguistico;
- HTML e attachment reali, assenti dal catalogo `v1`;
- metriche di retrieval, citazioni o precedenza canonica;
- memoria/throughput dell'intera pipeline IMAP → PostgreSQL.

`contains_real_pii=false`, domini riservati e
`sensitivity=synthetic_non_real` sono dichiarazioni/guard contrattuali, non un
detector PII.

## Test automatici offline

Sono coperti:

- schema, profili, cataloghi, date e versioni;
- determinismo e pubblicazione atomica;
- reader e indice fixture;
- builder RFC822 e INTERNALDATE corrente;
- checkpoint, purge-only, lock e resume;
- risposta APPEND ambigua;
- truncation e fault UID simulati;
- path KB stabile e restore soft-delete;
- gate AI e tenant restoration.

Il test del cap usa limiti ridotti e dati simulati: non dimostra un run reale da
5.001+ messaggi.

## Certificazioni live da aggiungere

### IMAP

- APPEND completo e purge-only;
- crash/reconnect;
- resume;
- mailbox reale oltre 5.000 messaggi;
- compatibilità Gmail su campione.

### PostgreSQL/pgvector

- 6.000 parent e zero attachment per `large`;
- tenant/progetto/metadata corretti;
- un documento logico per fixture dopo rerun;
- restore dopo rollback;
- zero righe nel tenant `default`.

### Retrieval

Per ciascuna azienda:

- fatto canonico ripetuto in email;
- fatto operativo presente solo nelle email;
- thread multi-messaggio;
- fatto storico/superato;
- errore seguito da correzione;
- proposta non approvata;
- domanda cross-company;
- precedenza del canonico corrente.

Recall@5, rifiuto, citazioni e p95 devono essere misurati prima di fissare soglie
di accettazione. Le soglie numeriche precedentemente proposte non sono risultati
misurati.

## Checklist

- [x] Unit/contract.
- [x] Quality gate SQLite bounded.
- [x] Determinismo.
- [x] Canary ID e frase cross-company.
- [x] Fault/resume simulati.
- [x] Path stabile e restore testati.
- [ ] Scanner PII.
- [ ] Near-duplicate.
- [ ] E2E IMAP reale.
- [ ] PostgreSQL/pgvector.
- [ ] Retrieval e precedenza canonica.
- [ ] Baseline performance/costi.

## Criterio di uscita

La qualità statica è completa. La fase RAG si chiude solo dopo una scorecard live
che dimostri isolamento, citazioni corrette e nessuna regressione rispetto al
gold.

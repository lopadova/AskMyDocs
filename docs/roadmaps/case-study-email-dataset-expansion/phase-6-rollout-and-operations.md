# Fase 6 — Rollout, documentazione e operazioni

## Obiettivo

Consegnare un runbook reversibile e distinguere gli artefatti offline pronti dal
rollout live non ancora eseguito.

## Stato delle tranche

| Tranche | Stato |
|---|---|
| `gold` 751 | generato e validato offline |
| `demo` 3.000 | generato e validato offline |
| `large` subset azienda | supportato e testato offline |
| `large` 6.000 | generato e validato offline |
| truncation oltre cap | algoritmo testato con cap ridotto |
| `stress` 30.000 | generato e validato offline |
| IMAP/PostgreSQL/retrieval | non certificato |

La presenza di 5.000 record per mailbox nel profilo `stress` non è una prova
live del caso 5.001+ nella stessa mailbox.

## Sequenza live raccomandata

1. Rigenerare il profilo con il codice corrente.
2. Eseguire determinism check e validator.
3. Eseguire il preflight di conteggi.
4. Eseguire il preview distruttivo e annotare token, attore e scope.
5. Certificare un subset su IMAP locale con lo stesso attore e token.
6. Completare una sola azienda.
7. Drenare le code e verificare DB/tenant/audit.
8. Eseguire retrieval e isolamento.
9. Estendere alle altre aziende.
10. Misurare costo, throughput, RSS e p95.
11. Promuovere la dataset version soltanto con scorecard verde.

`--estimate-cost` non produce una stima monetaria: le misure di costo entrano
nella scorecard live.

## Stop e resume

- interrompere nuovi APPEND senza rimuovere
  `storage/app/email-seed-checkpoints/`;
- non rigenerare o modificare il manifest;
- lasciare terminare i job già in esecuzione;
- ripartire con stessa versione e `--resume`;
- se cambia il checksum, creare una nuova dataset version o ripristinare
  l'artefatto originale.

## Rollback per dataset version

1. Fermare nuovi sync e drenare le code.
2. Emettere un token per la rimozione esatta:

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

3. Ripetere lo stesso comando sostituendo `--preview-purge` con
   `--confirm-token=<token>`.
4. Soft-delete i documenti con tenant, progetto e versione esatti:

   ```bash
   docs/case-studies/teardown.sh \
     --tenant=<tenant> \
     --project=<project> \
     --dataset-version=<versione>
   ```

5. Ripetere soltanto per i tenant target.
6. Verificare gold, canonici e altre aziende.
7. Conservare manifest, conteggi e audit ID.

Non usare `default`, wildcard, hard delete, `--purge` o
`--purge-all-seeded` per un rollback di versione.

Se la stessa versione viene poi riconsegnata, il path stabile
`dataset_version + fixture_id` permette al bridge di ripristinare la proiezione
soft-deleted esatta prima dell'ingest.

## Requisiti operativi

```env
KB_DISK_THROW=true
CASE_STUDY_EMAIL_DATASET_ROOT=storage/app/demo-email-datasets
CASE_STUDY_EMAIL_REQUIRE_FIXTURE_INDEX=true
```

Worker `connectors` e `kb-ingest` devono essere attivi. Il profilo `stress` usa
un server IMAP locale usa-e-getta, non un account Gmail condiviso.

## Osservabilità

Durante il rollout monitorare:

- installazioni e `connector_sync_runs`;
- depth/age delle code e `failed_jobs`;
- checkpoint APPEND e watermark UID;
- documenti/chunk per tenant e dataset version;
- errori Auto-Wiki/change analysis inattesi;
- FinOps e metriche provider;
- RSS, throughput e p95 retrieval.

Non esiste ancora un run report persistente dedicato al dataset né una matrice
di costi/capacità misurata.

## Artefatti disponibili

- profili e cataloghi `v1`;
- generator, reader, indice fixture e validator;
- manifest/shard rigenerabili;
- delivery streaming, lock, checkpoint, purge-only, token monouso e audit;
- path KB stabile e restore;
- gate AI e sync contiguo;
- orchestratore;
- [runbook operativo](../../testing/email-ingest-e2e.md);
- teardown tenant/version-scoped.

Artefatti ancora mancanti:

- scorecard RAG live;
- report costi/capacità misurati;
- report unico persistente per run;
- certificazione IMAP/PostgreSQL/Gmail (vedi
  [report bloccato](CERTIFICATION-2026-07-28.md)).

## Checklist

- [x] Profili offline generati e validati.
- [x] Subset per azienda.
- [x] Fault injection con cap ridotto.
- [x] Stop/resume documentato.
- [x] Purge-only e rollback tenant-scoped.
- [x] Orchestratore e help riallineati.
- [x] Budget file descriptor, environment gate, token e audit.
- [ ] IMAP locale reale.
- [ ] PostgreSQL/pgvector.
- [ ] Retrieval/precedenza canonica.
- [ ] Costi e performance misurati.
- [ ] Test reale 5.001+.
- [ ] Gmail campione.

## Criterio di uscita

Il core offline e il runbook sono completi. Il rollout è completo soltanto dopo
la scorecard live sulle tre aziende.

# Certificazione esterna dataset email — 2026-07-28

## Esito

**BLOCCATA su infrastruttura esterna.**

Questo documento non certifica APPEND, purge, ingest, retrieval o performance
live. Il ramo contiene gate offline e istruzioni riproducibili, ma la sessione
di remediation non disponeva contemporaneamente di:

- server IMAP locale usa-e-getta con credenziali di sei mailbox;
- PostgreSQL con pgvector e worker `connectors`/`kb-ingest`;
- provider embedding autorizzato e budget di misura;
- account Gmail di certificazione con finestra operativa approvata.

Nessun risultato simulato viene presentato come evidenza live.

## Evidenza offline richiesta prima del live

```bash
./vendor/bin/phpunit

ulimit -n 256
php artisan demo:generate-case-study-emails --profile=stress --force --stats
php artisan demo:generate-case-study-emails --profile=stress --check --stats
php artisan demo:validate-case-study-emails --profile=stress
```

Il test di regressione del writer confronta inoltre l'intero albero SHA-256 tra
budget di 2 e 64 handle. La directory temporanea deve risultare assente dopo
successo e fallimento.

## Procedura live riproducibile

Usare `operator:certification-2026-07-28` come attore in entrambi i passaggi.
Sostituire `<versione>` e `<token>` senza cambiare selezione o opzioni:

```bash
php artisan mail:seed-imap \
  --all \
  --dataset-version=<versione> \
  --purge-dataset \
  --summary-only \
  --actor=operator:certification-2026-07-28 \
  --preview-purge

php artisan mail:seed-imap \
  --all \
  --dataset-version=<versione> \
  --purge-dataset \
  --summary-only \
  --actor=operator:certification-2026-07-28 \
  --confirm-token=<token>
```

Poi:

1. installare/sincronizzare le sei mailbox e drenare le code;
2. registrare conteggi parent/chunk per `tenant_id + project_key +
   dataset_version`;
3. ripetere con `--resume` e provare che i conteggi non aumentino;
4. interrompere un APPEND, riprenderlo e verificare checkpoint/Message-ID;
5. eseguire il rollback `--purge-dataset --purge-only` con un nuovo token;
6. misurare throughput APPEND, RSS, ingest, costo embedding e p95 retrieval;
7. eseguire Recall@5, isolamento cross-tenant e precedenza canonica;
8. ripetere un campione approvato su Gmail.

## Criterio di sblocco

La certificazione diventa `PASS` soltanto allegando output, timestamp, versioni,
checksum manifest, conteggi DB, audit ID per mailbox e scorecard retrieval/costi.

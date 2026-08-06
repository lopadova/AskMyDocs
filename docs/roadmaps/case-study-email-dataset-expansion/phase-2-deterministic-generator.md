# Fase 2 — Generatore deterministico

## Obiettivo

Compilare offline cataloghi e profili in un dataset JSONL riproducibile, senza
LLM, rete o crescita della memoria PHP con il corpus.

## Struttura

```text
database/seeders/email-dataset/
  catalogs/
    v1/
      catalog.json
      companies/<company>/{facts,personas,scenarios,canaries}.json
  profiles/{gold,demo,large,stress}.json
  schema/email-v2.schema.json

app/Services/Demo/EmailDataset/
  CatalogLoader.php
  DatasetProfile.php
  DatasetVersion.php
  DeterministicRandom.php
  EmailDatasetGenerator.php
  EmailRecordFactory.php
  EmailRecordValidator.php
  EmailDatasetQualityValidator.php
  FixtureMetadataIndex.php
  JsonlDatasetWriter.php
  EmailDatasetReader.php
  DatasetPublisher.php

storage/app/demo-email-datasets/<dataset_version>/
  manifest.json
  <company>/<mailbox>/<yyyy-mm>.jsonl
  indexes/fixtures/<prefix>.jsonl
```

Gli output in `storage/` sono artefatti generati e ignorati da Git.

## Identità

Versione standard:

```text
case-study-email-v2-<generator_revision>-<profile>-s<seed>-catalog<version>-snap<fingerprint_16>
```

Message-ID:

```text
<dataset-version.fixture-id@fixtures.askmydocs.invalid>
```

Una selezione parziale di aziende/mailbox riceve una versione distinta. Il
reader verifica che la versione richiesta coincida con il manifest e con ogni
record.

`snapshot_fingerprint` è il commitment SHA-256 completo su:

- `GENERATOR_REVISION`;
- profilo e schema v2;
- indice del catalogo;
- `facts`, `personas`, `scenarios` e `canaries` delle aziende selezionate;
- fixture gold delle mailbox selezionate, quando il profilo le include.

I path logici e i rispettivi checksum sono ordinati prima dell’hash. Il
fingerprint completo è nel manifest; le prime 16 cifre entrano nella versione.
Il generatore fa discovery, calcola il commitment, ricarica tutti gli input
value-bearing e verifica nuovamente il fingerprint prima della pubblicazione.
Un file cambiato durante il run rende l’artefatto temporaneo non pubblicabile.

## Pipeline

1. Validare profilo e `catalogs/<version>`.
2. Ordinare aziende, mailbox e scenari.
3. Normalizzare il gold.
4. Allocare il residuo esatto per scenario.
5. Generare contenuto, truth state e thread tramite PRNG addressable.
6. Validare ogni record prima della scrittura.
7. Scrivere shard JSONL e indice metadata fixture per prefisso.
8. Calcolare checksum e manifest.
9. Eseguire il quality gate globale.
10. Pubblicare atomicamente la directory.

L'indice fixture è checksum-verificato e consente al bridge host di recuperare
metadata semantici senza incorporarli in header IMAP.

## CLI

```bash
php artisan demo:generate-case-study-emails \
  --profile=large \
  --seed=20260723 \
  --catalog-version=v1 \
  --output=storage/app/demo-email-datasets \
  --stats
```

| Opzione | Funzione |
|---|---|
| `--profile=` | `gold`, `demo`, `large`, `stress` |
| `--seed=` | seed deterministico non negativo |
| `--catalog-version=` | directory immutabile sotto `catalogs/` |
| `--company=*` | subset aziende |
| `--mailbox=*` | subset mailbox |
| `--output=` | root artefatti |
| `--check` | rigenera in temporanea e confronta i byte |
| `--force` | rerun idempotente: accetta solo file tutti byte-identici |
| `--stats` | riepilogo compatto |

`--check` richiede che l'artefatto pubblicato corrisponda al codice corrente.
`--force` rigenera in temporanea e confronta il SHA-256 di ogni path: se anche un
solo byte differisce, rifiuta la sostituzione e scarta la temporanea. Un nuovo
contenuto richiede una nuova identità; cambiare l’algoritmo richiede il bump di
`GENERATOR_REVISION`, cambiare il catalogo richiede una nuova versione catalogo.

## Quality gate bounded

`EmailDatasetQualityValidator` non conserva in array PHP tutte le identità o i
thread. Crea un database SQLite temporaneo con:

- unique index su `fixture_id`, Message-ID e hash subject+body generato;
- righe thread ordinate su disco;
- streaming di un thread alla volta, massimo 8 messaggi.

In memoria restano counter a cardinalità bounded, cataloghi e il thread
corrente. Il file SQLite viene rimosso con controllo esplicito dell'esito.

Il validator controlla:

- conteggi manifest;
- riferimenti catalogo;
- domini riservati;
- identità e contenuti generati duplicati;
- catene thread e correzioni;
- canary ID e frasi-canary cross-company.

Non controlla PII semantica o similarità near-duplicate.

## Manifest

Contiene:

- schema, dataset version, profilo, seed e catalog version;
- revisione generatore e `snapshot_fingerprint` completo;
- selezione e timeline;
- conteggi per azienda, mailbox, mese, scenario, truth state e tipo messaggio;
- distribuzione thread;
- checksum per shard e aggregato;
- definizione checksum dell'indice fixture;
- `contains_real_pii=false` come dichiarazione del dataset;
- elenco dei validator e contratti di compatibilità.

## Checklist

- [x] Profili e catalog loader versionato.
- [x] Content commitment degli input e rilevazione mutazioni durante il run.
- [x] PRNG deterministico e allocazione esatta.
- [x] Adapter gold e generatore.
- [x] Writer JSONL streaming.
- [x] Indice metadata fixture sharded.
- [x] Manifest e checksum.
- [x] Quality gate SQLite bounded.
- [x] Pubblicazione atomica.
- [x] CLI, determinismo e failure test.
- [x] Profili gold/demo/large/stress generati offline.

## Criterio di uscita

Due generazioni con gli stessi input producono gli stessi byte. Una modifica a
un input impegnato produce un nuovo fingerprint/versione; nessuna opzione può
mutare in place i byte di una versione già pubblicata.

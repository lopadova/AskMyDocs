# Stato implementazione — dataset email case-study

Data di verifica: 2026-07-28.

## Esito

Il core offline è implementato. `large` è il profilo promosso per la successiva
certificazione live.

| Profilo | Record | Per azienda | Per mailbox | Shard dati | SHA-256 aggregato dati |
|---|---:|---:|---:|---:|---|
| `gold` | 751 | 242–262 | 119–136 | 71 | `68cc51d53aa07f5f0c97f735bbf18a7b95b5696b3df5e0b9e3962b28fbeb1770` |
| `demo` | 3.000 | 1.000 | 500 | 179 | `3455311d9e111070fc433990a98c9ef75ebe56d10c35a349819efbfc91ebb256` |
| `large` | 6.000 | 2.000 | 1.000 | 180 | `e4b3dc57d8a772d85523a86405f61d3bd600cc116b55814faea247669f7940d6` |
| `stress` | 30.000 | 10.000 | 5.000 | 180 | `cfa80dbad498353a243dec450519ab965e4f1a3aa08c1f10b7a439fb464ce677` |

Il checksum aggregato sopra riguarda gli shard email. Gli artefatti correnti
includono anche l'indice metadata fixture:

| Profilo | Shard indice | SHA-256 aggregato indice |
|---|---:|---|
| `gold` | 240 | `526571bd589c36f9e6b91b591c7660615ad198136cc687621185f0952c0d90eb` |
| `demo` | 256 | `aefc71efbb6ae456576eaac9d081fdc3c8934941a072b901098fb0f7af265b07` |
| `large` | 256 | `c8a7a1a8b9b687dd6e664acb224f3da3c6a1c5efc9d4c0ce4c4dcf16664d1730` |
| `stress` | 256 | `6e9644437d66310874564c7eac9645dc4168569d727323152c189c35ad33b105` |

### Identità immutabili pubblicate

| Profilo | Dataset version | Snapshot fingerprint completo |
|---|---|---|
| `gold` | `case-study-email-v2-g1-gold-s20260723-catalogv1-snapede2f4abda9f7009` | `ede2f4abda9f700963d1a073439287e774165d2a9d3218299b2c10127953858c` |
| `demo` | `case-study-email-v2-g1-demo-s20260723-catalogv1-snapa951e9cb2e6563c9` | `a951e9cb2e6563c9a5d364e159c73a28cdb52b97403a706ec70dc839300b0c19` |
| `large` | `case-study-email-v2-g1-large-s20260723-catalogv1-snapa48c0f4751b501df` | `a48c0f4751b501dfc5e8e197daab04dc39cc358f994461b6295ef915d4d24ee9` |
| `stress` | `case-study-email-v2-g1-stress-s20260723-catalogv1-snapfa1d1a0f4517df7c` | `fa1d1a0f4517df7c3c66f6464206a95edb33d003da1b75f61bd05938b5fd6169` |

Tutti usano `generator_revision=g1`. Il fingerprint è il content commitment
su revisione, profilo, schema, catalogo/registri selezionati e fixture gold
incluse. Il generatore rilegge gli input dopo il commitment e lo ricalcola
prima del publish, quindi una mutazione durante il run fallisce.

## Profilo `large`

- 751 record gold normalizzati e 5.249 generati;
- 3.900 messaggi in thread (65%);
- thread di 2, 3, 4, 5 o 8 messaggi;
- 1.002 messaggi transactional e 347 report;
- truth state: 5.554 `current`, 329 `proposal`, 54 `corrected`,
  35 `incorrect`, 28 `superseded`;
- timeline narrativa 2024-01-01 → 2026-06-30;
- zero `fixture_id` e Message-ID duplicati;
- zero coppie subject+body duplicate tra i record generati;
- circa 11 MiB di shard email.

## Registri `catalogs/v1`

Ogni azienda dispone attualmente di:

- 6 personas;
- 9 fatti;
- 9 scenari;
- 4 canarini.

Non esiste un overlay editoriale che trasformi le reply apparenti del gold in
thread reali: i 751 record storici vengono normalizzati deterministicamente
come messaggi standalone `message_type=gold`, `truth_state=current`.

## Stato per fase

| Fase | Stato | Evidenza |
|---|---|---|
| 0 | core offline completo | identità, lock, restore, tenant e gate AI |
| 1 | completa | schema v2 e registri `catalogs/v1` |
| 2 | completa offline | generator, profili, JSONL, manifest, validator |
| 3 | completa offline | APPEND one-by-one, checkpoint, resume e purge scoped |
| 4 | hardening host completo | path stabile, metadata da indice, sync contiguo |
| 5 | qualità statica completa | SQLite bounded, thread, cataloghi e canarini |
| 6 | documentazione completa | orchestratore, runbook e rollback |

Le parole “completa” non includono la certificazione su servizi esterni.

## Garanzie implementate

- output deterministico da profilo, seed e catalog version;
- identità legata a revisione generatore e snapshot fingerprint degli input;
- cataloghi immutabili per directory (`catalogs/v1`);
- pubblicazione atomica e fallimento rumoroso; `--force` accetta solo un rerun
  byte-identico e non sostituisce mai una versione con contenuto diverso;
- reader streaming con verifica checksum;
- writer con budget LRU di 64 handle condiviso fra shard dati e indice;
- regressione byte-identica con eviction forzata a due handle;
- quality index SQLite temporaneo: memoria PHP bounded al thread/counter;
- controlli su domini riservati, identità, cataloghi, thread, duplicati esatti
  generati e frasi-canary cross-company;
- delivery sotto lease owner-safe rinnovabile della mailbox fisica, con hard
  deadline PCNTL, checkpoint atomico e recupero dell'APPEND ambiguo tramite
  Message-ID;
- `--purge-dataset --purge-only` per rimuovere senza riappendere;
- APPEND/purge reali rifiutati fuori da `local/testing`, senza override;
- token purge DB-backed monouso, hash-only e scope-bound, consumato con
  `lockForUpdate` dopo il preflight;
- audit `started/completed/failed/rejected` per mailbox e tenant, con argomenti
  allowlisted;
- purge-intent atomico per mailbox fisica: un crash dopo la cancellazione
  remota viene recuperato da un plain `--resume` prima di leggere checkpoint
  potenzialmente stale;
- installazione IMAP aggiornata in place;
- watermark UID contiguo e ripresa dopo truncation;
- `KB_DISK_THROW=true` obbligatorio sul disco KB usato dall'IMAP;
- parent fixture pubblicato su un path stabile
  `.../datasets/<dataset_version>/<fixture_id>.md`;
- restore tenant-scoped della stessa proiezione soft-deleted;
- metadata semantici risolti dall'indice fixture checksum-verificato;
- documenti email non canonici;
- Auto-Wiki e change analysis esclusi soltanto dopo autenticazione del
  Message-ID nel namespace riservato.

## Limiti non coperti dal quality gate

- nessuna detection semantica di PII reale;
- nessun algoritmo di similarità near-duplicate;
- nessuna email HTML o attachment nel catalogo `v1`;
- nessun benchmark live di retrieval/citazioni;
- nessun costo embedding misurato;
- nessun run report persistente dedicato al dataset.

## Comandi offline

```bash
php artisan demo:generate-case-study-emails --profile=large --force --stats
php artisan demo:generate-case-study-emails --profile=large --check --stats
php artisan demo:validate-case-study-emails --profile=large
php artisan mail:seed-imap --all --profile=large --summary-only --estimate-cost
```

Il primo comando non muta una versione esistente: con `--force` confronta ogni
file e accetta soltanto byte identici. Per contenuto diverso serve una nuova
versione/fingerprint e, se cambia l’algoritmo, una nuova revisione generatore.

`--estimate-cost` è un preflight di conteggi. Non calcola un prezzo: token e
costo dipendono dal provider e devono essere misurati durante la certificazione.

## Certificazione esterna ancora richiesta

Stato e prerequisiti riproducibili:
[`CERTIFICATION-2026-07-28.md`](CERTIFICATION-2026-07-28.md). Lo stato è
esplicitamente `BLOCCATA`; nessun test fake viene presentato come risultato
live.

- APPEND/resume/purge su IMAP reale;
- ingest completo dei 6.000 parent su PostgreSQL/pgvector;
- rerun live senza duplicati e restore dopo rollback;
- costi e tempi reali degli embedding;
- retrieval Recall@5, isolamento e precedenza canonica;
- mailbox reale con più di 5.000 messaggi;
- compatibilità Gmail su un campione;
- capacity delivery/ingest del profilo `stress`.

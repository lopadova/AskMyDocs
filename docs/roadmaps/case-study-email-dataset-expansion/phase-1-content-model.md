# Fase 1 — Contratto e modello dei contenuti

## Obiettivo

Definire un contratto email v2 e input editoriali versionati per le tre aziende.

## Strati del profilo `large`

| Strato | Record |
|---|---:|
| Gold normalizzato | 751 |
| Generato | 5.249 |
| **Totale** | **6.000** |

Il risultato ha 2.000 messaggi per azienda e 1.000 per mailbox.

## Contratto email v2

Campi principali:

| Campo | Contratto corrente |
|---|---|
| `fixture_id` | SHA-256 stabile |
| `dataset_version` | scope di build, delivery e rollback |
| `company_key`, `mailbox_key` | ownership e destinazione |
| `scenario_type`, `topic`, `message_type` | classificazione |
| `thread_id`, `in_reply_to`, `references` | catena RFC822 quando in thread |
| `message_id` | namespace `fixtures.askmydocs.invalid` |
| `to`, `cc`, mittente, subject, body | contenuto sintetico |
| `sent_at`, `date`, `internal_date` | timeline narrativa nel dataset |
| `fact_ids`, `canonical_sources` | provenienza editoriale |
| `truth_state` | `current`, `superseded`, `proposal`, `incorrect`, `corrected` |
| `canary_ids` | controlli di ownership |
| `sensitivity` | `synthetic_non_real` |

Lo schema accetta anche `body_html`, `headers` e `attachments`. Nel catalogo
`v1`, però, il generatore emette `body_html=null` e `attachments=[]`: HTML e
allegati non fanno parte del corpus attuale.

`internal_date` nello shard conserva la timeline narrativa. Durante APPEND il
builder usa intenzionalmente l'ora corrente come INTERNALDATE remoto, così una
fixture storica appena consegnata non viene esclusa da un sync incrementale
basato su `last_sync_at`. L'header `Date` resta storico.

## Gold

I 751 record di `database/seeders/emails/*.json` vengono normalizzati senza
riscrivere i file raw:

- identità e Message-ID deterministici;
- assegnazione a scenario/fatti per la mailbox;
- `message_type=gold`;
- `truth_state=current`;
- nessun `thread_id`, `In-Reply-To` o `References`.

Non esiste un overlay manuale di thread o truth state sul gold.

## Cataloghi versionati

La versione corrente è:

```text
database/seeders/email-dataset/catalogs/v1/
  catalog.json
  companies/
    rotta-logistics/
    prometeo-antincendio/
    passolibero-calzature/
```

Ogni azienda contiene:

| Registro | Elementi |
|---|---:|
| Personas | 6 |
| Fatti | 9 |
| Scenari | 9 |
| Canarini | 4 |

Una modifica pubblicata non riscrive `v1`: crea `catalogs/v2/` e usa
`--catalog-version=v2`.

### Fact registry

Ogni fatto ha ID, statement, fonte canonica e scenari autorizzati. Il validator
richiede che le fonti inizino con
`case-studies/<company_key>/`.

### Persona registry

Le personas hanno nome/email sintetici, ruolo e scenari consentiti. Per i
record generati il mittente deve appartenere alle personas dello scenario.

### Scenario registry

Gli scenari definiscono mailbox, topic, peso, personas, fatti e template text.
I pesi del profilo producono conteggi esatti per mailbox.

### Canary registry

I 4 canarini di ogni azienda hanno un solo owner e una lista di scenari
consentiti. Il validator controlla sia gli ID dichiarati sia la comparsa
letterale di una frase-canary di un'altra azienda.

## Distribuzioni verificate di `large`

- 3.900 messaggi in thread (65%);
- 1.002 transactional;
- 347 report;
- thread di lunghezza 2, 3, 4, 5 e 8;
- timeline narrativa 2024-01-01 → 2026-06-30;
- truth state: 5.554 `current`, 329 `proposal`, 54 `corrected`,
  35 `incorrect`, 28 `superseded`;
- CC sintetici opzionali;
- nessun HTML o attachment nella versione `v1`.

## Gate contenutistici implementati

- schema e date valide;
- domini email riservati;
- identità fixture e Message-ID uniche;
- mittenti, scenari, fatti e fonti risolvibili nei cataloghi;
- thread contigui, aciclici e cronologici;
- correzioni da `incorrect` a `corrected`;
- duplicati esatti subject+body vietati per i record generati;
- canary ID e frasi cross-company vietati.

Il validator non esegue detection semantica di PII e non misura near-duplicate.
Il campo `synthetic_non_real` e i domini riservati sono guard contrattuali, non
un sostituto di uno scanner PII.

## Checklist

- [x] Schema v2.
- [x] Cataloghi `v1` per tre aziende.
- [x] 6 personas, 9 fatti, 9 scenari e 4 canarini per azienda.
- [x] Distribuzioni e conteggi dei quattro profili.
- [x] Regole thread, timeline e truth state.
- [x] Contratto opzionale HTML/attachment.
- [ ] Contenuti HTML/attachment nel dataset.
- [ ] Overlay editoriale dei thread gold.
- [ ] Scanner PII e near-duplicate.

## Criterio di uscita

La fase è completa per il catalogo `v1`: il generatore non inventa a runtime
aziende, persone, fatti, scenari o distribuzioni fuori dai registri versionati.

# Fase 3 — Delivery IMAP streaming, idempotenza e resume

## Obiettivo

Consegnare migliaia di messaggi con memoria bounded, una sola connessione per
mailbox fisica e ripresa sicura dopo un errore ambiguo.

## Reader e builder

`EmailDatasetReader`:

- valida manifest, shard e indice fixture;
- legge JSONL riga per riga;
- filtra per mailbox senza materializzare il corpus;
- verifica la dataset version su manifest e record.

`EmailMessageBuilder`:

- emette Message-ID, `In-Reply-To` e `References` deterministici;
- aggiunge `To`, CC opzionale e header fixture riservati;
- mantiene la data narrativa nell'header `Date`;
- usa l'ora corrente come INTERNALDATE APPEND, così il sync incrementale vede
  messaggi storici appena consegnati;
- supporta HTML e attachment a livello di contratto, ma `catalogs/v1` produce
  soltanto text/plain e nessun attachment.

## Algoritmo APPEND

1. Prevalidare tutte le mailbox selezionate prima di mutarne una.
2. Acquisire la lease cross-tenant della mailbox fisica
   (`host + port + username`).
3. Installare la guardia hard-deadline PCNTL e aprire una connessione.
4. Caricare il checkpoint legato a dataset version e checksum.
5. Rinnovare owner-safe la lease e inviare un RFC822 alla volta.
6. Su esito ambiguo, riconnettere e cercare il Message-ID prima di riappendere.
7. Dopo ogni ACK, rinnovare la lease prima di avanzare/salvare il checkpoint.
8. Rilasciare il lock dopo purge, checkpoint e APPEND.

Il lock del seeder usa la stessa chiave del serializer IMAP ordinario e
deliberatamente non contiene `tenant_id`: protegge una risorsa fisica condivisa.
Il driver cache deve supportare lock atomici, refresh owner-safe e verifica
dell’owner corrente.

La lease non si limita ad avere un TTL lungo:

- viene rinnovata immediatamente prima di APPEND/purge e dopo ogni ACK;
- i timeout socket Webklex sono limitati dal budget residuo;
- SIGALRM interrompe l’intera operazione prima della safety boundary;
- un secondo alarm limita il cleanup prima della scadenza effettiva;
- handler, modalità async e alarm precedenti sono sempre ripristinati;
- PCNTL/SIGALRM assente o già occupato causa un errore esplicito.

Default: `CONNECTOR_IMAP_SEED_LOCK_TTL=14400` e
`CONNECTOR_IMAP_SEED_LOCK_SAFETY_MARGIN=30`; il margine minimo è 2 secondi.
Se ownership/refresh falliscono, l’operazione si ferma. Un APPEND eventualmente
accettato oltre la lease non raggiunge il callback checkpoint e viene
riconciliato via Message-ID al resume.

## Checkpoint

Il nome file identifica via hash server, porta, account, folder e dataset
version. Il payload include mailbox key, dataset version, checksum manifest,
ultima sequenza contigua, conteggi `appended`/`already_present` e timestamp.
L’eventuale messaggio ambiguo non entra nel checkpoint: `--resume` verifica per
Message-ID l’intervallo successivo non ancora confermato.

`--resume` rifiuta un checksum diverso. Un checkpoint completo rende il rerun
un no-op.

## Purge: due semantiche distinte

`--purge-dataset` è un'operazione “pulisci e riparti”: elimina la sola versione
selezionata, azzera il checkpoint e poi esegue nuovamente APPEND.

Per una rimozione senza riappendere:

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
`--preview-purge` con `--confirm-token=<token>`. Il token è DB-backed,
monouso, hash-only e legato a operazione, checksum, selezione normalizzata,
tenant, attore e argomenti. Viene consumato atomicamente dopo il preflight e
prima del primo delete remoto.

`--purge-all-seeded` e l'alias legacy `--purge` sono più ampi: eliminano tutte
le fixture seedate della mailbox. Non usarli per il rollback di una singola
versione.

Il purge remoto cerca l'header dataset e cancella a blocchi. La rimozione KB è
separata e usa `tenant_id + project_key + dataset_version`.
Purge e clear del checkpoint sono entrambi sotto la lease: il clear avviene
solo dopo un nuovo refresh owner-safe. Il purge ampio elimina inoltre tutti i
checkpoint di versioni precedenti appartenenti alla stessa mailbox fisica,
senza materializzare la directory in memoria.

Prima del primo delete remoto, lo store scrive atomicamente un unico marker
`<physical_mailbox_hash>.purge-intent.json` nella root dei checkpoint. Il
payload schema v1 lega mailbox fisica/logica, operazione, header/value e, per i
dataset generati, versione + checksum manifest. Ogni run mutante recupera
l’intent sotto lease prima di fidarsi di qualsiasi checkpoint: ripete il purge
idempotente, esegue `clear`/`clearAll` e rimuove il marker per ultimo. È quindi
sicuro ripartire con un semplice `--resume` dopo un crash tra purge remoto e
clear locale; il checkpoint completo stale non viene trattato come no-op.

## CLI

```bash
php artisan mail:seed-imap \
  --all \
  --profile=large \
  --batch-size=100 \
  --resume \
  --summary-only
```

| Opzione | Funzione |
|---|---|
| `--dataset-version=` | versione esatta |
| `--profile=` | versione standard del profilo |
| `--dataset-root=` | root artefatti |
| `--batch-size=` | intervallo checkpoint |
| `--resume` | ripresa dal checkpoint verificato |
| `--summary-only` | output compatto |
| `--progress-every=` | frequenza progress |
| `--estimate-cost` | preflight di conteggi senza rete |
| `--purge-dataset` | purge scoped seguito da APPEND |
| `--purge-only` | con `--purge-dataset`, solo rimozione |
| `--purge-all-seeded` | purge ampio |
| `--purge` | alias legacy ampio |
| `--preview-purge` | emette un token monouso senza rete |
| `--confirm-token=` | autorizza lo scope esatto del preview |
| `--actor=` | lega operazione e audit all'operatore |
| `--dry-run` | build/validazione senza invio |

APPEND e purge reali sono rifiutati fuori da `APP_ENV=local/testing`; non
esiste override. Dry-run, estimate e preview restano offline in ogni ambiente.
Ogni mailbox reale apre un audit tenant-scoped prima della mutazione.

`--purge-only` richiede `--purge-dataset` e non è combinabile con
`--dry-run`, `--estimate-cost` o `--resume`.

## Sync oltre il cap

Il wrapper host:

- osserva l'ordine UID restituito dal client;
- conferma un UID soltanto quando il filtro o tutti i dispatch previsti
  completano;
- blocca il watermark sul primo messaggio incompleto;
- persiste il prefisso contiguo;
- mantiene il vecchio `last_sync_at` quando il run viene troncato.

La logica è testata con cap ridotto e fault injection. Non equivale ancora a un
E2E su una mailbox reale da 5.001+ messaggi.

## Checklist

- [x] Reader JSONL iterabile.
- [x] Builder RFC822 deterministico.
- [x] INTERNALDATE corrente per il backfill.
- [x] APPEND one-by-one sotto lock fisico.
- [x] Lease rinnovabile owner-safe e hard deadline PCNTL.
- [x] Checkpoint atomico e reconcile ambiguo.
- [x] Purge scoped e `--purge-only`.
- [x] Purge-intent atomico e recovery idempotente dopo crash.
- [x] Installazione aggiornata in place.
- [x] Watermark UID contiguo.
- [x] Test di fault/resume.
- [x] Test di ACK oltre TTL, ownership loss, ripristino SIGALRM e crash
  post-purge con checkpoint stale.
- [ ] E2E su server IMAP reale.
- [ ] Certificazione reale oltre 5.000 messaggi.

## Criterio di uscita

Il contratto offline è completo. La fase live si chiude quando un run `large`
interrotto completa via `--resume` con un solo messaggio remoto per fixture ID.

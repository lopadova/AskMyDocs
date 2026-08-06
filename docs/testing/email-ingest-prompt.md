# Prompt riutilizzabile: estendere il dataset email case-study

Questo prompt guida un agente o uno sviluppatore nell’aggiunta di una nuova
azienda o di nuovi scenari al dataset email v2. Il runbook eseguibile è
[`email-ingest-e2e.md`](email-ingest-e2e.md).

## Prompt

> Estendi il dataset email case-study di AskMyDocs in modo deterministico.
>
> Regole:
>
> - scopri aziende, tenant, progetti e mailbox dal codice; non assumere un
>   elenco esterno;
> - non creare migliaia di JSON manuali e non usare un LLM durante la
>   generazione;
> - tratta `database/seeders/email-dataset/catalogs/<version>/` come immutabile:
>   per una modifica pubblicata copia la versione in una directory nuova e
>   incrementa `catalog_version`; non riscrivere `catalogs/v1`;
> - usa solo persone e indirizzi sintetici su domini riservati `.example` o
>   `example.com`;
> - assegna a ogni azienda canarini unici, mai presenti nei cataloghi delle
>   altre;
> - mantieni `tenant_id = project_key = azienda`;
> - tratta le email come fonti operative non canoniche;
> - rappresenta fatti superati, proposte ed errori con `truth_state` e una
>   correzione temporale esplicita;
> - conserva il corpus curato in `database/seeders/emails/*.json` come gold;
> - ogni Message-ID generato deve restare nel namespace riservato
>   `@fixtures.askmydocs.invalid`;
> - generation, validation e preflight devono funzionare senza rete o
>   credenziali.
>
> Procedura:
>
> 1. Esegui `php artisan demo:list-companies` e ispeziona
>    `database/seeders/TestEmailFixtures.php`.
> 2. Leggi i documenti canonici dell’azienda sotto
>    `docs/case-studies/data/<project_key>/`.
> 3. Crea `database/seeders/email-dataset/catalogs/<nuova-versione>/` a
>    partire dalla versione corrente e assegna la stessa nuova
>    `catalog_version` nel relativo `catalog.json`.
>    Definisci:
>    - mailbox e matrici scenario;
>    - persone/ruoli;
>    - fatti con fonte canonica e validità temporale;
>    - template per thread, transazioni e report;
>    - canarini aziendali.
> 4. Se aggiungi una mailbox, aggiorna `TestEmailFixtures::MAILBOXES` con
>    `mailbox_key`, `project_key`, `tenant_id`, `company_name`, account IMAP e
>    label.
> 5. Aggiorna il `catalog.json` della nuova directory e, soltanto se richiesto,
>    i profili sotto `database/seeders/email-dataset/profiles/`. La versione
>    `v1` corrente contiene per azienda 6 personas, 9 fatti, 9 scenari e 4
>    canarini: non dichiarare soglie editoriali maggiori come già implementate.
> 6. Genera un subset rapido:
>
>    ```bash
>    php artisan demo:generate-case-study-emails \
>      --profile=demo \
>      --catalog-version=<nuova-versione> \
>      --company=<project_key> \
>      --stats
>    ```
>
> 7. Verifica determinismo e qualità:
>
>    ```bash
>    php artisan demo:generate-case-study-emails \
>      --profile=demo \
>      --catalog-version=<nuova-versione> \
>      --company=<project_key> \
>      --check
>    php artisan demo:validate-case-study-emails \
>      --dataset-version=<versione-subset>
>    ```
>
> 8. Rigenera e valida il profilo `large` completo. Conferma conteggi esatti,
>    zero duplicati, zero canarini esteri e thread validi. Il quality gate usa
>    un indice SQLite temporaneo per le strutture corpus-sized; non sostituirlo
>    con array PHP globali.
> 9. Esegui il preflight senza rete:
>
>    ```bash
>    php artisan mail:seed-imap \
>      --all \
>      --profile=large \
>      --summary-only \
>      --estimate-cost
>    ```
>
> 10. Prima dell'IMAP verifica `KB_DISK_THROW=true`,
>     `CASE_STUDY_EMAIL_DATASET_ROOT` e
>     `CASE_STUDY_EMAIL_REQUIRE_FIXTURE_INDEX=true`. Solo in un ambiente
>     local/testing con credenziali di test, consegna la versione con
>     `--purge-dataset`; su interruzione usa `--resume`.
> 11. Installa o riconfigura i connettori con
>     `php artisan connector:imap:install --all --sync`.
> 12. Verifica path KB stabile per `dataset_version + fixture_id`, restore della
>     proiezione soft-deleted, documenti, tenant, metadata, assenza di
>     Auto-Wiki/change analysis, retrieval positivo e rifiuto cross-company.
> 13. Aggiorna runbook, help, `.env.example` e test se hai modificato contratti,
>     comandi o env.
> 14. Per rimuovere una versione da IMAP senza riappendere usa esclusivamente:
>
>     ```bash
>     php artisan mail:seed-imap \
>       --all \
>       --dataset-version=<versione> \
>       --purge-dataset \
>       --purge-only \
>       --summary-only
>     ```
>
> Vincoli di rendicontazione:
>
> - il catalogo `v1` attuale genera text/plain e nessun attachment; non
>   dichiarare HTML/allegati come coperti soltanto perché lo schema li accetta;
> - il validator blocca domini reali, duplicati esatti e canarini esteri, ma non
>   esegue detection semantica PII o near-duplicate;
> - `--estimate-cost` dà conteggi, non un prezzo;
> - test con cap ridotto non equivalgono a un E2E reale da 5.001+ messaggi;
> - separa sempre esiti offline da IMAP/PostgreSQL/retrieval/performance live.
>
> Output richiesto:
>
> - elenco dei cataloghi modificati;
> - dataset version;
> - record/shard/checksum;
> - conteggi per tenant, azienda e mailbox;
> - esito quality gate, determinismo e preflight;
> - esito dei test di isolamento;
> - eventuali verifiche live non eseguite per mancanza di credenziali.

## Riferimenti

- Cataloghi: `database/seeders/email-dataset/catalogs/<version>/`
- Profili: `database/seeders/email-dataset/profiles/`
- Gold curato: `database/seeders/emails/`
- Generatore: `app/Services/Demo/EmailDataset/`
- Comandi: `GenerateCaseStudyEmailsCommand`,
  `ValidateCaseStudyEmailsCommand`, `MailSeedImapCommand`
- Delivery: `ImapMailboxSeeder`, `EmailMessageBuilder`,
  `WebklexMailboxAppender`
- Ingest marker: `HostIngestionBridge`
- Gate AI: `IngestDocumentJob`
- Sync oltre cap: `SerializedConnectorSyncJob`,
  `App\Connectors\Imap\ImapSyncProgressContext`

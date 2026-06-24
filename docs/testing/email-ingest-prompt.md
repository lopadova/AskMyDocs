# Prompt agnostico — estendere il test e-mail→IMAP→ingest→chat alle aziende presenti

Questo file è un **prompt riutilizzabile** (per un agente AI o per uno
sviluppatore) che: (1) scopre quali aziende esistono già nel sistema, (2) capisce
quali hanno documentazione, (3) estende il "seeder delle e-mail" con messaggi
realistici e coerenti col dominio di ciascuna, (4) li consegna in caselle IMAP
vere, (5) installa il connettore e fa l'ingest, (6) esegue i test di chat per
account. È **agnostico** sui nomi azienda: non assumere mai un elenco fisso —
scoprilo a runtime.

Il runbook operativo completo è
[`email-ingest-e2e.md`](email-ingest-e2e.md). Questo prompt orchestra quel
runbook in modo ripetibile.

---

## Copia-incolla: prompt per l'agente

> **Obiettivo.** Verifica end-to-end l'ingest di e-mail reali via IMAP per le
> aziende già presenti in AskMyDocs, e abilita i test di chat per account.
>
> **Regole.**
> - Sii agnostico sui nomi: **scopri** le aziende, non assumerle.
> - Non seedare e-mail come righe DB. Le e-mail devono essere **inviate
>   davvero** (IMAP APPEND) e poi ingerite dal connettore.
> - Ogni e-mail deve contenere un **"fatto-esca" unico** (codice/nome/numero)
>   che identifica l'azienda e non deve mai comparire nelle risposte di altre
>   aziende (rilevatore di contaminazione per il test di isolamento, R30).
> - Tenant `default` salvo diversa evidenza. Tutto è dev/test: non toccare la
>   produzione.
>
> **Passi.**
> 1. **Discovery.** Esegui `php artisan demo:list-companies`. Annota, per ogni
>    `project_key`: nome, #documenti, #chunk, membri (email) e se ha un
>    connettore. Identifica le aziende **con documentazione** (docs > 0) e i
>    `project_key` **orfani** (docs ma 0 membri).
> 2. **Selezione.** Per il test di chat servono aziende con (a) documentazione e
>    (b) almeno un utente membro che possa fare login. Elenca i candidati e
>    scegli quelli da coprire (di default: tutti quelli con docs > 0 e ≥1 membro).
> 3. **Capire il dominio.** Per ogni azienda scelta, ispeziona la sua
>    documentazione (admin KB tree, oppure i `knowledge_documents` per quel
>    `project_key`) per capire il settore e il lessico (es. logistica, sicurezza
>    antincendio, e-commerce calzature). Le e-mail devono suonare plausibili in
>    quel dominio e **coerenti** con i documenti già presenti.
> 4. **Estendere le fixtures.** In `database/seeders/TestEmailFixtures.php`
>    (ogni azienda ha **2 caselle** → 2 `mailbox_key`, es. `<project>-1`,
>    `<project>-2`):
>    - aggiungi/aggiorna in `MAILBOXES` un'entry per ogni casella selezionata:
>      `mailbox_key`, `project_key`, `company_name`, `email` (casella Gmail
>      dedicata), `host`/`port`/`encryption`/`validate_cert`, `password_env`
>      (es. `CONNECTOR_TEST_<AZIENDA>_<N>_PASSWORD`);
>    - aggiungi le e-mail in `database/seeders/emails/<mailbox_key>.json` (array
>      JSON; le 2 caselle della stessa azienda hanno e-mail diverse; ≥100 per
>      casella, vario tipo + thread domanda/risposta) con le chiavi `subject`,
>      `from_name`, `from_email`, `date`, `body_text`. Inserisci i fatti-esca
>      unici dell'azienda e annota quali sono (servono per i test di §8). Per la
>      generazione di massa usa un multi-agente (un agente per casella/batch che
>      scrive il proprio file).
>    - `configJson()` è già generico (host/port/encryption dal fixture + env
>      override, `folders.include=[INBOX]`, `date_window_days`): non modificarlo.
> 5. **Credenziali.** Per ogni nuova casella crea l'account Gmail di test con
>    App Password e metti la password nella env `password_env` in `.env`
>    (mai committarla). Aggiorna `.env.example` con le nuove chiavi (vuote).
> 6. **Anteprima.** `php artisan mail:seed-imap --all --dry-run` e verifica
>    oggetti/contenuti.
> 7. **Consegna.** `php artisan mail:seed-imap --all` (usa `--purge` per re-run
>    puliti). Conferma su Gmail che le e-mail sono arrivate.
> 8. **Connettore + ingest.** Assicurati che la coda giri
>    (`QUEUE_CONNECTION=sync` o `php artisan queue:work`) e che i provider AI
>    (embeddings + chat) siano configurati, poi
>    `php artisan connector:imap:install --all --sync`. Punta SEMPRE il
>    connettore al `project_key` **esistente** dell'azienda così i membri
>    correnti vedono subito le e-mail; se usi un project_key nuovo, concedi la
>    membership con `auth:grant <email> viewer --project=<key>`.
> 9. **Verifica ingest.** `php artisan demo:list-companies`: i conteggi
>    `docs`/`chunks` devono essere cresciuti per ogni azienda.
> 10. **Test di chat per account.** Per ogni azienda: login come suo utente,
>     chiedi qualcosa la cui risposta sta in una e-mail ingerita → verifica
>     risposta grounded + citazione al messaggio. Poi fai la **prova di
>     isolamento**: chiedi a quell'account il fatto-esca di un'ALTRA azienda →
>     deve rispondere "nessun contesto", mai rivelarlo.
> 11. **Report.** Riassumi: aziende coperte, #e-mail inviate/ingerite per
>     azienda, esito dei test di chat e di isolamento, eventuali project_key
>     orfani trovati e come risolti.
>
> **Aggiornamento aggiunte di codice.** Se aggiungi nuove env, aggiorna
> `.env.example`; se cambi i comandi o le fixtures, aggiorna
> `email-ingest-e2e.md`. Mantieni i test verdi (`vendor/bin/phpunit
> tests/Feature/Console tests/Unit/Services/Demo`).

---

## Note di implementazione (riferimenti rapidi)

- Fixtures: [`database/seeders/TestEmailFixtures.php`](../../database/seeders/TestEmailFixtures.php)
  (`MAILBOXES`, `mailboxKeys()`, `mailboxKeysForProject()`, `passwordFor()`,
  `configJson()`, `emailsForMailbox()` → `database/seeders/emails/*.json`,
  `SEED_HEADER`).
- Consegna: `app/Console/Commands/MailSeedImapCommand.php` →
  `app/Services/Demo/ImapMailboxSeeder.php` (+ `EmailMessageBuilder`,
  `WebklexMailboxAppender`).
- Connettore: `app/Console/Commands/ConnectorImapInstallCommand.php` →
  `App\Services\Admin\Connectors\ConfigureConnectorService`.
- Discovery: `app/Console/Commands/DemoListCompaniesCommand.php`.
- Lacuna membership (perché "la chat non trova niente"): l'ingest da connettore
  NON crea `projects`/`project_memberships`; vedi §6 del runbook.

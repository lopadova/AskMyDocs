# Header fisso per API protette da Cloudflare

Nel modulo di creazione o modifica della connessione API, inserire in
**Cloudflare rule token** un valore casuale e privato. AskMyDocs lo invia come
`X-AskMyDocs-Cloudflare-Token` nei test degli endpoint, nei test delle
configurazioni non ancora salvate, nei drill list/detail e nelle chiamate dei
tool, insieme al profilo di autenticazione già configurato.

Il campo è facoltativo: svuotarlo rimuove l'header. Per usare un nome diverso,
inserire `Nome: Valore` in **Default headers**, una riga per header.

## Regola Cloudflare

Creare una custom rule limitata all'host e al percorso dell'API, confrontando
il valore dell'header. Esempio da adattare al proprio dominio, percorso e valore:

```text
(http.host eq "api.example.com"
 and starts_with(http.request.uri.path, "/api/")
 and any(http.request.headers["x-askmydocs-cloudflare-token"][*] eq "VALORE_PRIVATO"))
```

Scegliere **Skip** e selezionare i controlli che causano il blocco o la challenge.
Posizionare la regola prima delle custom rule che bloccano il traffico e
verificare il match nei Security Events di Cloudflare. L'header da solo non
modifica le regole Cloudflare. Skip non supera Bot Fight Mode del piano Free
né un blocco precedente, come una regola IP Access con azione Block.
Riferimenti ufficiali: [Skip](https://developers.cloudflare.com/waf/custom-rules/skip/),
[ordine delle fasi](https://developers.cloudflare.com/waf/troubleshooting/phase-interactions/),
[header nelle espressioni](https://developers.cloudflare.com/ruleset-engine/rules-language/fields/reference/http.request.headers/).

## Comportamento e manutenzione

Gli header della connessione sono valori predefiniti. I parametri header della
rotta hanno la precedenza; gli header del profilo di autenticazione prevalgono
su entrambi. Il confronto dei nomi ignora maiuscole e minuscole.
Gli header condivisi non entrano nei parametri registrati nei log né negli
schemi dei tool. Il token dedicato è mascherato nel modulo e omesso dagli export:
durante l'import occorre inserire un nuovo valore, come per gli altri header
contenenti credenziali. Come gli altri default header, il valore è salvato nel
JSON `headers` della connessione; la mascheratura è una funzione dell'interfaccia.

Il pacchetto `connector-api` beta.5 salva gli header condivisi ma li omette nel
planner delle richieste. `scripts/composer/patch-api-connector-headers.php`
applica la correzione al planner e alla precedenza degli header dopo
`composer install` e `composer update`. L'operazione è idempotente e rifiuta
sorgenti inattese prima di scrivere i file. Quando il pacchetto incorporerà la
correzione, verificare il comportamento e rimuovere script e hook Composer.
Se l'installazione usa `--no-scripts`, eseguire esplicitamente
`php scripts/composer/patch-api-connector-headers.php` prima di servire richieste.

### Verifica dopo un deploy

Gli hook `post-install-cmd` e `post-update-cmd` in `composer.json` sono necessari
finché resta installata la beta.5. Senza questi hook un nuovo `vendor/` perde la
correzione anche se il token risulta salvato nel modulo della connessione.
Un checkout già corretto può continuare a funzionare e nascondere il problema.

Per correggere un'installazione esistente, eseguire:

```bash
php scripts/composer/patch-api-connector-headers.php
```

Riavviare gli eventuali worker persistenti, quindi ripetere **Testa chiamata**.
`ApiConnectorSharedHeadersTest` verifica gli header in uscita sia dall'endpoint
`routes/test-config` usato dalla scheda Prova sia da `routes/{id}/test` usato
dopo il salvataggio, insieme all'header `Authorization` del profilo API.
`SharedHeadersComposerPatchTest` verifica anche che entrambi gli hook restino
registrati. Un 403 persistente richiede la verifica della regola e del Ray ID
nei Security Events di Cloudflare; il solo stato 403 non prova l'assenza del token.

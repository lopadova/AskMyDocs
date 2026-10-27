# Configurazione guidata e cataloghi dei modelli

La pagina **Sistema → Configurazione** è riservata ai super admin. Le sezioni
separano istruzioni operative, provider e modelli, integrazioni e controlli
dell’installazione. L’ambito può essere l’intera organizzazione oppure un
progetto esistente. I campi riservati all’organizzazione rimangono in sola
lettura nell’ambito di progetto.

Le modifiche rimangono nel form fino a **Salva modifiche**. Il salvataggio
batch valida ogni campo prima di scrivere, in una singola transazione. Il
cambio di provider rimuove le selezioni dei modelli dalla bozza e ripristina
l’instradamento standard: scegliere eventuali nuovi modelli prima di salvare.
Un modello generale impostato prevale sui modelli delle singole fasi.

## Compilazione tramite agente

**Scrivi con l’agente** prepara le istruzioni operative; **Configura con
l’agente** può proporre anche modelli, instradamento e frequenza di
sincronizzazione. Il flusso è: descrizione → proposta con motivazioni →
**Compila il form** → revisione manuale → **Salva modifiche**.

Il generatore usa `AiManager` e le credenziali già configurate. Restituisce
solo valori validati per i campi consentiti nell’ambito corrente. Non salva,
non esegue tool, non cambia provider, permessi o interruttori di rollout.
Gli errori lasciano intatta la bozza del form. I campi non registrati non
entrano nel contesto inviato al modello.

## Disponibilità e prezzi

Il selettore interroga il provider scelto e permette di cercare per nome o ID.
Mostra ingresso e uscita **per milione di token**, valuta, fonte e data del
prezzo. I prezzi mancanti sono `n/d`, distinti dal prezzo zero. Si tratta di
listini; sconti personali e consumo effettivo restano nella rendicontazione
FinOps. I modelli esplicitamente incompatibili con i tool o in dismissione
sono visibili ma non selezionabili.

| Provider | Disponibilità | Prezzi |
| --- | --- | --- |
| OpenRouter | `/models` | Prezzi restituiti dal provider, USD |
| Regolo | `/model_group/info` | Prezzi restituiti dal provider, EUR |
| OpenAI | `/models` | Fonte LiteLLM già integrata in FinOps, USD |
| Anthropic | `/models`, con paginazione | Fonte LiteLLM già integrata in FinOps, USD |
| Gemini | `/models`, con paginazione e filtro `generateContent` | Fonte LiteLLM già integrata in FinOps, USD |

Gli override manuali FinOps mantengono precedenza, valuta, unità e data
effettiva di modifica. Il catalogo è isolato per organizzazione, provider,
endpoint e credenziali. La cache dura un’ora; **Aggiorna catalogo** richiede
subito nuovi dati e aggiorna anche la fonte prezzi dei provider nativi.

Se la chiamata fallisce, l’ultimo catalogo valido resta consultabile per
sette giorni con il suo timestamp originale e un avviso di obsolescenza.
Non si possono salvare nuove selezioni da un catalogo obsoleto. Una fonte
prezzi indisponibile non nasconde i modelli disponibili: mantiene i prezzi
precedenti e la loro data, oppure mostra `n/d`. Credenziali mancanti e
credenziali rifiutate hanno messaggi distinti; chiavi e risposte di errore del
provider non vengono esposte al browser.

Gli endpoint aggiunti sotto `/api/admin/app-settings` sono `PUT /batch`,
`GET /models`, `POST /models/refresh` e `POST /assist`, con gli stessi
controlli di autenticazione, organizzazione e ruolo della configurazione.
Refresh e generazione hanno un limite di dieci richieste al minuto.

Riferimenti dei contratti: [OpenRouter](https://openrouter.ai/docs/api/api-reference/models/list-all-models-and-their-properties),
[Regolo](https://docs.regolo.ai/models/catalog/),
[OpenAI](https://developers.openai.com/api/reference/resources/models/methods/list),
[Anthropic](https://platform.claude.com/docs/en/api/models/list),
[Gemini](https://ai.google.dev/api/models).

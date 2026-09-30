# Provenienza della risposta in chat

Domande come «Dove hai trovato questi dati?» o «Quali fonti hai usato?» vengono riconosciute dal preprocessore strict come `transition=provenance`. Il riconoscimento è semantico e multilingua, non basato su una lista di parole in PHP. Richiede `CHAT_REASONING_ENABLED=true`, come il resto del focus conversazionale.

## Comportamento

- Il riferimento è la risposta dell'assistente immediatamente precedente nella stessa conversazione/sessione widget. Una risposta senza citazioni non prende in prestito le fonti di una risposta più vecchia.
- La risposta viene composta dal server usando le citazioni e i `tool_sources` registrati: titoli di email/documenti, nome dello strumento MCP/API, connettore e data della consultazione. Non usa la prosa precedente come prova e non include tutti i risultati della ricerca o strumenti non selezionati.
- Le citazioni KB sono descritte come riferimenti registrati, non come prova che ogni frase sia stata verificata. I passaggi e i payload storici non vengono ripubblicati come dati attuali.
- Sono ricontrollati tenant, conversazione, progetto, attore, accesso documentale e filtri correnti. Per MCP/API vengono verificati l'esecuzione persistita, il risultato originale tramite hash, l'identità e l'autorizzazione attuale del connettore. Non si effettua una chiamata al servizio esterno.
- Fonti revocate, archiviate, non più verificabili o escluse dai filtri non vengono nominate. La risposta segnala l'eventuale incompletezza senza inventare un'origine.
- Si mantiene il focus aziendale precedente. Un'attribuzione non diventa un nuovo fatto aziendale comunicato.

## Percorsi e diagnostica

`AnswerProvenance` è condiviso da agente (inclusa la voce che lo usa), chat sincrona, SSE e widget. L'investigation restituisce `answer_provenance` prima della ricerca e del controllo sul profilo aziendale. L'agente salta pianificazione, strumenti, sintesi generativa e JEV.

Resta una sola chiamata al preprocessore per il nuovo messaggio, riutilizzata nei retry dello stesso turno. Non è una cache generale delle risposte aziendali: richieste di aggiornamento o domande miste che richiedono nuovi fatti conservano il normale percorso di ricerca.

Nel debug, `grounding.provenance` contiene l'ID della risposta attribuita, le fonti accessibili, il conteggio dei riferimenti non disponibili e `new_searches=0`, `new_tool_calls=0`. `semantic_validation.used=false` indica che l'attribuzione è deterministica. Le fonti storiche non incrementano i contatori delle nuove chiamate.

## Rilascio e test

Nessuna migrazione, reset dei dati o build frontend. Dopo il deploy PHP, eseguire `php artisan queue:restart` per far caricare il codice ai worker gestiti dal supervisore. Il flag generale della memoria/focus rimane il rollback.

```sh
vendor/bin/phpunit tests/Feature/Chat/AnswerProvenanceTest.php
```

La suite copre isolamento, ACL e filtri, hash e connettori disabilitati, messaggi concorrenti, risposte senza fonti, attribuzione mirata, focus preservato, retry agente e parità tra sync, SSE e widget, senza chiamate esterne.

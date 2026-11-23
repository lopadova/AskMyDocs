# Collaudo del contesto e della risposta

Chiedi al generatore di cercare le fonti con i permessi dell'utente indicato.

```bash
php artisan agent:context 'Mostra i resi di Gescat e spiega la regola di rimborso' \
  --tenant=acme --project=resi --user=42 --locale=it > contesto.json
```

Il JSON contiene la domanda originale, le sottodomande interpretate, le query e il loro esito. In `evidence` ci sono i documenti con i passaggi raccolti, le coordinate per leggere sezioni mirate e le osservazioni dei connettori con il momento della lettura. Il comando non compone una risposta e non scrive in una chat.

Per riprodurre una domanda a metà conversazione, passa anche `--history=cronologia.json`. La cronologia è un array in ordine cronologico di oggetti con `role` (`user` o `assistant`) e `content` testuale. La domanda passata al comando è il turno corrente.

Se una lettura è ancora in corso, il comando restituisce `ready: false` e l'identificativo della run. Per esportare il risultato quando il connettore ha terminato, usa lo stesso tenant e utente.

```bash
php artisan agent:context --resume=UUID --tenant=acme --user=42 > contesto.json
```

Per collaudare la composizione, usa il file completo prodotto dalla stessa installazione.

```bash
php artisan agent:answer contesto.json > risposta.json
```

Il secondo comando ricontrolla i permessi e verifica le affermazioni sulla raccolta salvata. Non avvia ricerche documentali, letture aggiuntive o richieste ai connettori. `answer` contiene la spiegazione, `citations` e `tool_sources` indicano le fonti e `artifact` contiene il widget quando applicabile. I record descrivono quanto osservato durante la raccolta. Conserva il contesto come un file di lavoro privato perché può contenere informazioni aziendali.

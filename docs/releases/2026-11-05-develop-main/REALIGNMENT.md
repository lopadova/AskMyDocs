# Riallineamento dopo la PR

Il consolidamento crea una storia nuova sopra main. Non incorpora come antenati i 588 vecchi commit esclusivi di develop: è proprio questo che rende leggibile la futura PR.

Un merge `main → develop` dopo la release rende main antenato di develop, ma **non elimina i 588 vecchi commit dalla storia esclusiva di develop**. I diff successivi possono essere corretti, mentre la lista dei commit continua a essere affollata. Il solo merge di ritorno non soddisfa quindi l’obiettivo di pulire anche la storia del ramo di integrazione.

Per riallineare anche la storia, dopo il merge verificato della PR:

1. Sospendere brevemente le integrazioni in develop e verificare eventuali commit arrivati dopo lo snapshot `2a61388391141bcc459cdd01a8dceb740aa9f08f`. Non devono essere persi: vanno trasferiti su un ramo dalla nuova main.
2. Conservare un riferimento archivio duraturo e un bundle verificato del develop precedente. I backup locali creati qui sono il punto di partenza, non un archivio remoto già pubblicato.
3. Concordare esplicitamente con il maintainer il riallineamento del riferimento develop alla nuova main. Questa operazione riscrive un ramo condiviso/protetto e richiede un’attività separata autorizzata; non viene eseguita da questo task.
4. Aggiornare i checkout e ricreare o riallineare le feature ancora aperte dalla nuova base. Non fare merge automatici dei vecchi branch nel nuovo develop, perché reintrodurrebbero la storia che si sta archiviando.
5. Verificare che main sia antenato di develop e che il confronto delle punte sia vuoto prima di riaprire il flusso normale delle feature.

L’alternativa senza riscrittura è conservare la storia originale tramite merge ordinari; rimane sicura per il contenuto ma conserva anche i commit storici. Nessun reset, force-push, cambio di protezioni o cancellazione di branch viene preparato come azione automatica.

Questa nota chiarisce il passaggio successivo; main e develop restano invariati durante la preparazione della PR.

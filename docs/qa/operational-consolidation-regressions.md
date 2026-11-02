# Registro delle regressioni di consolidamento

Questa matrice usa esempi sintetici ricavati dalle classi di difetto censite.
Nomi, codici e namespace degli esempi non appartengono ai clienti. Non contiene
il corpus live, né afferma che le fasi successive siano già implementate.
Le aspettative di valutazione rimangono separate dai prompt inviati all'agente.

| ID | Scenario anonimizzato | Riscontro richiesto | Gate |
|---|---|---|---|
| R01 | Worker interrotto dopo aver verificato `ORD-041`; ripresa e planner lento | Il consumo logico continua; fatto recuperato; nessun nuovo dispatch senza riserva finale | G1 |
| R02 | Due callback per la stessa operazione e risultato arrivato dopo la chiusura | Un solo effetto; nessuno strumento aperto; risultato tardivo rifiutato | G1 |
| R03 | Fonte lunga con valore selezionato oltre il limite della proiezione | Originale intatto; hash/versione e passaggio recuperabili dopo compattazione | G2 |
| R04 | `ORD-041` in due progetti, due casi con gli stessi interlocutori | Nessuna fusione fra namespace o casi; ogni citazione mantiene l'identità completa | G2 |
| R05 | Permesso revocato dopo la memorizzazione; vecchia conferma riusata | Nuovo accesso negato; riuso non rinnova acquisizione o scadenza | G2 |
| R06 | Prima conferma positiva, negazione successiva e condizione temporale | Eventi distinti; nessun veto indipendente diventa approvazione per assenza/errore | G3 |
| R07 | Decisione Jev interrotta e ripresa; repair copre solo una sottodomanda | Nessun retry dall'esito incerto; lacune motivate per sottodomanda | G3 |
| R08 | Fatto composto distribuito su due righe, due tabelle e bozza cortese | Valori letterali invariati; copertura sull'insieme; tabelle separate; richiesta non trasformata in fatto o invio | G4 |
| R09 | Tracking formattato, IBAN valido e risposta HTTP 200 con errore XML | Codice operativo distinto da dato personale; segreti sempre protetti; errore applicativo rilevato anche in retest/import manuale | G5 |
| R10 | Costo mancante, gratuità verificata, USD/EUR e due prenotazioni concorrenti | Ignoto distinto da zero; valute separate; nessun superamento per moltiplicazione dei processi | G0/G5 |

## Evidenze disponibili della fase 0

`tests/Feature/Agent/QaCampaignTest.php` verifica il percorso HTTP reale con
risposte provider simulate, SDK testo/embedding, tutti e tre i percorsi di
inferenza, modello non ammesso, costo sconosciuto, retry dopo errore di rete,
rilascio idempotente, revoca dell'attore, isolamento tenant, sorgenti ristrette,
contesto obbligatorio nei seguiti, revisione del worker e rollback della lettura.
Il test di concorrenza lancia due processi PHP distinti contro lo stesso file
SQLite. Non è una verifica del database PostgreSQL distribuito.

`tests/Unit/Scripts/test_chat_api_client.py` e `test_qa_campaign.py` verificano
che un invio incerto non sia ripetuto, che gli input privati mantengano il testo,
che i risultati attesi restino fuori dagli input e che prezzi assenti o
supplementi non gestiti impediscano il manifesto. Le tariffe zero devono essere
presenti esplicitamente nel catalogo; i default non dimostrano gratuità.

Il corpus live fissato contiene dieci prime domande immutate. Gli esiti e i
seguiti delle cronologie storiche non vengono riutilizzati come baseline. Per
ciascun caso la scheda privata `oracle-review.json` deve indicare originali,
date/versioni, attese e inferenze vietate prima di valutarne il risultato.

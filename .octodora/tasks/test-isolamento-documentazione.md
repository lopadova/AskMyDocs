```markdown
# SPEC di TASK: Test isolamento documentazione

## Contesto
Il progetto `AskMyDocs` prevede un sistema di Q&A basato su documentazione indicizzata. Per garantire la privacy dei dati e la correttezza delle risposte, è necessario verificare che ciascuna azienda (caso studio) veda esclusivamente la propria documentazione. Il task si inserisce nell'attività di testing funzionale dopo l'ingest dei documenti.

- **Repository**: `lopadova/AskMyDocs`
- **Sub-task già noti**: ritrovamento documentazione casi studio, creazione utenti/progetti, ingest documenti, test richieste.
- **Gate già previsti**: (non specificati nel materiale)

## Obiettivo
Verificare che, per ogni azienda caso studio:
1. L'utente di AziendaX possa ottenere risposte solo da documenti di AziendaX.
2. L'utente di AziendaX **non** riceva risposte basate su documenti di AziendaY o AziendaZ.
3. Le risposte non contengano frammenti testuali provenienti da documenti esterni all'azienda.

Possibilmente in forma automatizzata (script di test) o comunque con passi manuali ripetibili.

## File probabilmente coinvolti
- `tests/test_isolation.py` (da creare, se si segue pattern standard di pytest)
- `src/query_engine.py` o `src/rag_pipeline.py` (componente che recupera e filtra documenti per utente/progetto)
- `src/auth/user_projects.py` (mapping utente -> progetti)
- `src/ingest/document_loader.py` (metadati di progetto associati ai documenti)
- Eventuali fixture di test in `conftest.py` per setup dei tre casi studio.

## Passi di implementazione
1. **Recupero dati di test**  
   - Identificare per ogni caso studio: utente, progetto, lista dei documenti caricati (già fatti in sub-task precedenti).
   - Assicurarsi che ci sia almeno un documento per azienda con contenuto distinguibile (es. nome azienda nel testo).

2. **Scelta del metodo di test**  
   - **Opzione A (automatico)**: Scrivere test pytest che chiamano l'endpoint di query (o la funzione interna) per ogni utente, verificando che i chunk restituiti appartengano solo al progetto dell'utente.
   - **Opzione B (manuale)**: Definire una checklist di domande specifiche (es. “Qual è il fatturato di AziendaX?”) e verificare che solo l’utente di AziendaX riceva risposta pertinente.

3. **Implementazione test automatico (consigliato)**  
   - Creare `tests/test_isolation.py`.
   - Utilizzare le fixture già esistenti per utenti, progetti e documenti.
   - Per ogni utente:
     - Eseguire una query tipica (es. “Descrivi l’azienda”).
     - Ottenere i documenti sorgente della risposta (metadati con `project_id` o `company_id`).
     - Assert: tutti i sorgenti appartengono al progetto dell'utente.
   - Inoltre, provare una query con contenuto noto di un’altra azienda (es. “Parla di [AziendaY]”) e verificare che IA risponda “Non trovato” o che i sorgenti siano comunque vuoti/null.

4. **Esecuzione e registrazione**  
   - Eseguire il test in ambiente di staging (o locale con dati reali).
   - In caso di fallimenti, segnalare bug e iterare.
   - Documentare eventuali eccezioni (es. documenti condivisi tra progetti) da discutere col team.

## Criteri di accettazione (gate)
- [ ] **Test di isolamento positivo**: per ogni utenza, tutte le risposte generate si basano esclusivamente su documenti del proprio progetto.
- [ ] **Test di isolamento negativo**: nessuna risposta per utente di AziendaX contiene frammenti di documenti di AziendaY o Z.
- [ ] **Copertura dei tre casi studio**: tutti e tre gli scenari sono verificati.
- [ ] **Riproducibilità**: il test è automatizzato (script passante) o, in alternativa, esiste una checklist manuale che ha prodotto esito positivo.
- [ ] **Nessun falso positivo**: le query generiche (es. “Ciao”) non devono rompere il test ma possono restituire risposte vuote o generiche.

## Rischi
- **Documenti con metadati incompleti**: se il campo `project_id` non è popolato, il test non può distinguere i sorgenti. Servono dati di ingest corretti.
- **Query ambigue**: domande che potrebbero avere risposte in più aziende (es. “Cos’è il GDPR?”) potrebbero dare falsi positivi. Limitare le query a contenuti specifici dell'azienda.
- **Modello LLM che inventa**: se il modello genera risposte senza attaccarsi ai documenti (allucinazione), il test fallirebbe anche se l’isolamento è corretto. Prevedere un controllo dei sorgenti, non solo del testo.
- **Performance**: test su molti documenti può essere lento. Considerare un subset di documenti per il test.
```
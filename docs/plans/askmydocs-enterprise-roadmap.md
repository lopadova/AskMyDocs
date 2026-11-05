# AskMyDocs Enterprise — roadmap completa

> **Per gli agenti:** il grafo operativo canonico è `TASKS.json` nel pacchetto del 26 settembre 2026. Prima di implementare un task leggere `docs/tasks/<ID>.md`, aggiornare l'evidenza e applicare il metodo complete-journey per UI/API/integration work.

**Obiettivo:** portare AskMyDocs, i suoi package e i contratti di integrazione a una piattaforma enterprise verificabile, mantenendo Vocentra come stream gestito dall'agente dedicato.

**Regola di avanzamento:** l'ordine segue le dipendenze; `PLANNED` non è completato, un test fixture non prova un journey reale, e `NOT_VERIFIED`/`BLOCKED` resta aperto.

## Stato iniziale

E00.01 è stato eseguito su AskMyDocs HEAD `96848ed95dc6f7169fc4ae7bf117158f0c322d4d`. Il bootstrap Artisan è bloccato dalla divergenza Routines lock/vendor; il guard DAG è verde e resta protettivo. Vocentra ha owner separato: le attività V sono coordinate tramite contratti, non implementate in questo workspace.

## Roadmap per milestone

### E — piattaforma e governance AskMyDocs

| ID | Task | Dipendenze |
|---|---|---|
| E00.01 | Discovery e registro delle differenze | — |
| E00.02 | Matrice delle dipendenze e release riproducibile | E00.01 |
| E00.03 | Contratto tenant e partizionamento cache | E00.01 |
| E01.01 | Principal e ExecutionContext comuni | E00.02, E00.03 |
| E01.02 | Autenticazione forte e recovery reali | E01.01 |
| E01.03 | IAM shadow e enforcement progressivo | E01.01 |
| E02.01 | Schemi delle azioni e classificazione dati | E01.03 |
| E02.02 | Approvazione vincolata e receipt | E02.01, E01.02 |
| E02.03 | Gateway delle azioni per tutti i runtime | E02.02 |
| E03.01 | Adottare DAG con isolamento verificato | E00.03, E02.03 |
| E03.02 | Bounded agents e delega IAM | E03.01 |
| E03.03 | Mandati Routines e trigger Flow Connect | E03.02 |
| E04.01 | Reservation ledger gerarchico | E02.01 |
| E04.02 | Metering di tutti i percorsi AI | E04.01, E03.02 |
| E04.03 | Allocazioni fra servizi | E04.02 |
| E05.01 | Evidence e lineage comuni | E02.01 |
| E05.02 | PII e retention su tutti i confini | E05.01 |
| E05.03 | Audit correlato e compliance operativa | E05.02, E04.02 |
| E06.01 | Traiettorie complete e dataset autorizzati | E03.02, E05.02 |
| E06.02 | EverPrompt enterprise e release registry | E06.01, E01.03 |
| E06.03 | Gate di promozione e quality routing | E06.02, E04.02 |
| E07.01 | Voce full-duplex nel portale | E02.03, E04.02 |
| E07.02 | Console unica e passaporto agente | E05.03, E06.03 |
| E07.03 | Onboarding, profili e operatività | E07.02 |
| E08.01 | Restore, revoche e cancellazioni | E05.03, E03.03 |
| E08.02 | Conformance policy multi-client e supply chain | E07.03, E08.01 |
| E08.03 | Accettazione della piattaforma completa | E08.02, W08.03, V06.03 |

### W — gap competitivi AskMyDocs/WeKnora

| ID | Task | Dipendenze |
|---|---|---|
| W00.01 | Inventario dei gap ancora aperti | E00.01 |
| W00.02 | Protocollo comparativo e soglie preregistrate | W00.01 |
| W00.03 | Runner di valutazione riproducibile | W00.02, E06.01 |
| W01.01 | Reranking neurale e fallback | W00.02, E04.02 |
| W01.02 | Fusion, diversificazione e filtri reali | W01.01 |
| W01.03 | Retrieval Lab e regressioni | W01.02, W00.03 |
| W02.01 | Parsing e locatori multimodali | W00.01, E05.01 |
| W02.02 | Parent-child e context packing | W02.01, W01.02 |
| W02.03 | Navigazione grafo con limiti e provenienza | W02.02, E05.01 |
| W03.01 | Claim support e astensione calibrata | W02.03, E05.01, W00.03 |
| W03.02 | Evidence Explorer | W03.01 |
| W03.03 | Decision Intelligence e rejected approaches | W03.02 |
| W04.01 | Dataset snapshot e schema | W02.01, E05.02 |
| W04.02 | Query DuckDB in runner isolato | W04.01, W05.02 |
| W04.03 | Workspace risultati e grafici | W04.02, W05.03 |
| W05.01 | Runner contract e threat model | E02.03 |
| W05.02 | Isolamento e identità di esecuzione | W05.01, E03.02 |
| W05.03 | Artifact lifecycle e cleanup | W05.02, E05.02 |
| W06.01 | Catalogo skill qualificato | W05.03, E06.02 |
| W06.02 | MCP discovery progressiva | W06.01, E03.02 |
| W06.03 | Browser governato | W06.02, W05.02 |
| W07.01 | Memoria personale e istituzionale | E05.01, W03.03 |
| W07.02 | Canali conversazionali inbound | E02.03, W07.01 |
| W07.03 | Ricerca web e connettori con ACL | E05.02, W01.02 |
| W08.01 | UX integrata e onboarding | W03.02, W04.03, W06.03, W07.02 |
| W08.02 | Red team e recovery trasversali | W08.01, E08.02, W07.03 |
| W08.03 | Confronto finale e report pubblico | W08.02, W00.03 |

### V — Vocentra/addon, coordinato con owner separato

| ID | Task | Dipendenze |
|---|---|---|
| V00.01 | Riconciliare specifiche e stato dei tre repo | E00.01 |
| V00.02 | Decisione auth standalone/enterprise | V00.01, E01.03 |
| V00.03 | OpenAPI addon e compatibility manifest | V00.02, E02.01 |
| V01.01 | Connection registry e origin policy | V00.03 |
| V01.02 | Identity link e project mapping | V01.01, E01.01 |
| V01.03 | Source gateway e privacy negoziata | V01.02, E05.02 |
| V02.01 | Conversation gateway e run remoto | V01.03, E03.02 |
| V02.02 | Stream, polling e stati remoti | V02.01 |
| V02.03 | Revisione fedele e fonti per claim | V02.02, W03.01 |
| V03.01 | Snapshot della nota e proposta remota | V02.01, E05.01 |
| V03.02 | Eventi bidirezionali e riconciliazione | V03.01 |
| V03.03 | Delete distribuita e revoca dei derivati | V03.02, E08.01 |
| V04.01 | Anteprima autorevole delle azioni | V02.02, E02.03 |
| V04.02 | Step-up e conferma multi-superficie | V04.01, E02.02 |
| V04.03 | Ricevute, budget e unknown | V04.02, E04.03 |
| V05.01 | Bundle aziendali di stile, glossario e template | V02.03, E06.02 |
| V05.02 | Mobile, keyboard e native journey addon | V04.03, V05.01 |
| V05.03 | Handoff e complementarità vocale | V05.02, E07.01 |
| V06.01 | Doctor, entitlement e supportabilità | V05.03, E07.03 |
| V06.02 | Journey completo a due backend | V06.01, V03.03 |
| V06.03 | Release compatibile e handoff implementatori | V06.02 |

## Gate di esecuzione

1. **E00.02:** riparare installazione/vendor e produrre matrice lock, PHP/Laravel, package, licenze e SBOM.
2. **E00.03:** chiudere le chiavi di partizionamento tenant/policy/prompt/model/ACL con prove cross-tenant e worker reale.
3. **E01–E02:** identità, auth, policy e ActionGateway prima di qualsiasi azione agentica.
4. **E03–E06:** orchestration, budget, evidenze, PII e eval con contratti già chiusi.
5. **W/V:** eseguire solo quando le dipendenze dichiarate sono realmente integrate; Vocentra mantiene il proprio ledger.
6. **E07–E08:** superfici e release soltanto dopo complete journeys, race/fault tests, restore, revoche e supply-chain evidence.

## Metodo di verifica obbligatorio

Per ogni task: stato Git e SHA; comando esatto, exit code e ambiente; test positivi/negativi; eventuali fake/provider simulati; UI → API reale → persistenza → fresh read → denial; concorrenza con processi/connessioni reali quando richiesta; limiti e rollback. Nessuna suite skipped, fixture o installazione package è prova di funzionamento end-to-end.

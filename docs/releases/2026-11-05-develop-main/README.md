# Consolidamento develop → main — 5 novembre 2026

Il ramo raccoglie tutte le modifiche nette di develop in commit tematici, partendo dalla main remota verificata. I contenuti già in produzione vengono conservati, come richiesto da Marco. Main e develop non vengono riscritti.

## Snapshot e perimetro

- Base della nuova storia: `origin/main` = `4048fe996f9ef88f30a196e7738df5a2b06eaf22`.
- Sorgente: `origin/develop` e `refs/heads/develop` = `2a61388391141bcc459cdd01a8dceb740aa9f08f`.
- Antenato comune: `ebc12f22a6528b4a16963e4cab01afd17d682d7f` (20 settembre 2026).
- Storia originale: 588 commit esclusivi di develop, dei quali 549 non merge; 55 commit esclusivi di main.
- Confronto iniziale delle due punte: 2.477 file (1.726 aggiunti, 636 modificati, 115 eliminati).
- Risultato applicativo riconciliato rispetto a main: 2.343 file (1.726 aggiunti, 611 modificati, 6 eliminati), oltre a questo dossier.
- 134 file esclusivi di main conservati byte per byte; 31 file condivisi riconciliati, con 14 conflitti espliciti risolti.

## Percorso di lettura

1. [Descrizione pronta per la futura PR](PR.md).
2. [Funzioni di main conservate e risoluzioni](MAIN-PRESERVATION.md).
3. [Verifiche e limiti di validazione](VALIDATION.md).
4. [Riallineamento dopo il merge](REALIGNMENT.md).
5. [Inventario completo e provenienza in JSON](manifest.json).

## Commit e inventari per file

[Elenco dei 15 commit tematici e verifica della tree applicativa](COMMITS.md). Il sedicesimo commit contiene esclusivamente il dossier.

| Area | File | Inventario |
|---|---:|---|
| Piattaforma, dipendenze e integrazione | 47 | [01-platform-toolchain](sections/01-platform-toolchain.md) |
| Connettori API, MCP, OAuth e ingestione email | 340 | [02-connectors](sections/02-connectors.md) |
| Composizione ed esecuzione dei tool API | 67 | [03-api-tools](sections/03-api-tools.md) |
| Knowledge base, ricerca e documenti | 111 | [04-knowledge](sections/04-knowledge.md) |
| Chat, agent, grounding e memoria verificata | 435 | [05-chat-reasoning](sections/05-chat-reasoning.md) |
| Tenant, autenticazione e amministrazione | 63 | [06-tenancy-auth](sections/06-tenancy-auth.md) |
| Conversazione vocale e Realtime | 30 | [07-realtime](sections/07-realtime.md) |
| Consumi AI, prezzi e diagnostica | 41 | [08-finops-observability](sections/08-finops-observability.md) |
| Classificazione e campagne email | 35 | [09-email-triage](sections/09-email-triage.md) |
| Interfaccia applicativa e sistema visivo | 192 | [10-interface](sections/10-interface.md) |
| Client desktop e mobile | 73 | [11-desktop](sections/11-desktop.md) |
| Landing e immagini editoriali | 694 | [12-editorial-landing](sections/12-editorial-landing.md) |
| QA, fixture locali e operatività | 57 | [13-qa-operations](sections/13-qa-operations.md) |
| Specifiche, guide e governance | 94 | [14-project-documentation](sections/14-project-documentation.md) |
| Materiali e fonti marketing | 64 | [15-marketing](sections/15-marketing.md) |

Ogni inventario contiene una riga per file, stato, quantità di righe aggiunte/eliminate, descrizione del contenuto, contesti del diff e commit di provenienza. I file condivisi fra più funzioni vengono assegnati una sola volta all’area proprietaria; bootstrap e wiring trasversale sono raccolti nella piattaforma.

La classificazione riguarda il delta finale, quindi non ripropone file toccati e poi ripristinati nel corso dei vecchi commit. Le 549 revisioni applicative restano tracciabili nel manifest ma non diventano 549 commit della nuova PR.

## Backup

- `backup/main-before-consolidation-20261105` e `backup/develop-before-consolidation-20261105`.
- Bundle completo verificato: `.git/codex-backups/main-develop-consolidation-20261105.bundle` (126 MiB circa). Non viene aggiunto al repository.
- I riferimenti originali e gli altri worktree non vengono modificati.
- Gli elaborati locali non tracciati in `docs/reports/2026-11-02-fix-review/` sono estranei al consolidamento e restano esclusi.

## Eccezione di flusso

Questa è una ricostruzione della storia richiesta esplicitamente per rendere leggibile la PR verso main. Il branch parte da main invece che da develop; non modifica il normale GitFlow delle feature successive e non dichiara una nuova versione di release. Non vengono effettuati push, apertura PR, merge remoto o tag.

Esiste anche un tag storico chiamato `develop`: per evitare riferimenti ambigui le verifiche usano SHA completi o `refs/heads/develop` / `refs/remotes/origin/develop`.

## Documentazione aggiunta dal consolidamento

Le quindici pagine in `sections/` sono gli inventari per area elencati sopra. Completano il dossier questi otto file:

| File | Contenuto |
|---|---|
| `README.md` | Indice, perimetro, snapshot e backup |
| `PR.md` | Titolo e descrizione pronti per la futura PR |
| `COMMITS.md` | Elenco dei commit tematici e verifica della tree |
| `MAIN-PRESERVATION.md` | File di main conservati e scelte di riconciliazione |
| `REALIGNMENT.md` | Passaggio successivo per riallineare la storia di develop |
| `VALIDATION.md` | Esiti dei controlli, errori ereditati e gate ancora aperti |
| `manifest.json` | Inventario strutturato, provenienza e assegnazione dei 2.343 file |
| `checks.json` | Risultati strutturati delle verifiche locali |

# Commit del ramo consolidato

Quindici commit di contenuto ricostruiscono lo snapshot applicativo. Un ultimo commit aggiunge questo dossier, senza cambiare altri file applicativi. I commit intermedi servono alla revisione per area; la validazione applicativa riguarda il risultato completo.

| Ordine | Commit | Area | File |
|---:|---|---|---:|
| 1 | `5b80679bd` | Piattaforma, dipendenze e integrazione | 47 |
| 2 | `206002916` | Connettori API, MCP, OAuth e ingestione email | 340 |
| 3 | `39f6447f7` | Composizione ed esecuzione dei tool API | 67 |
| 4 | `731ae9e32` | Knowledge base, ricerca e documenti | 111 |
| 5 | `6dbb30bb7` | Chat, agent, grounding e memoria verificata | 435 |
| 6 | `c2ede0106` | Tenant, autenticazione e amministrazione | 63 |
| 7 | `33aaecc3e` | Conversazione vocale e Realtime | 30 |
| 8 | `809e1809d` | Consumi AI, prezzi e diagnostica | 41 |
| 9 | `236110fd2` | Classificazione e campagne email | 35 |
| 10 | `b9f6e0084` | Interfaccia applicativa e sistema visivo | 192 |
| 11 | `7ea868d8f` | Client desktop e mobile | 73 |
| 12 | `648847f21` | Landing e immagini editoriali | 694 |
| 13 | `4694972d5` | QA, fixture locali e operatività | 57 |
| 14 | `3edc97212` | Specifiche, guide e governance | 94 |
| 15 | `4686fa7db` | Materiali e fonti marketing | 64 |

## Verifica del contenuto

La tree applicativa all’ultimo commit di contenuto è `9556db7859aa9314d86352f01e700e1a82774d6a`. Corrisponde esattamente allo snapshot riconciliato e verificato, prima dell’aggiunta della documentazione di consolidamento.

La base della catena è la main remota `4048fe996f9ef88f30a196e7738df5a2b06eaf22`. I 588 commit storici esclusivi di develop non sono antenati di questa nuova catena.

```bash
git log --reverse --oneline 4048fe996f9ef88f30a196e7738df5a2b06eaf22..codex/feature/develop-main-consolidation-20261105
git diff --check 4048fe996f9ef88f30a196e7738df5a2b06eaf22 codex/feature/develop-main-consolidation-20261105
```

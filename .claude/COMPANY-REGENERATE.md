# Come rigenerare questa repo

Questa cartella non nasce da copia manuale file-per-file. La sorgente vera e' il generatore locale:

- `tools/generate_generalized_claude_repo.mjs`

## A cosa serve

Serve a trasformare la `.claude` del progetto sorgente in una versione:

- ripulita dai riferimenti troppo specifici
- riorganizzata per stack
- rigenerabile in modo deterministico

In pratica evita che la repo aziendale venga mantenuta a mano in modo incoerente.

## Come si usa

Dal progetto sorgente:

```powershell
node tools/generate_generalized_claude_repo.mjs
```

Questo comando ricrea la cartella locale:

- `_ai_company_claude/`

Poi quella cartella va sincronizzata nella destinazione aziendale.

## Workflow consigliato

1. Modifica `tools/generate_generalized_claude_repo.mjs`
2. Esegui il generatore
3. Controlla il diff in `_ai_company_claude/`
4. Copia o sincronizza nella repo/cartella aziendale
5. Verifica i file chiave: `README.md`, `INVENTORY.md`, `laravel/CLAUDE.md`

## Perche' non modificare direttamente la cartella generata

Perche' la modifica andrebbe persa alla rigenerazione successiva.

Se vuoi cambiare il contenuto finale, devi cambiare il generatore.

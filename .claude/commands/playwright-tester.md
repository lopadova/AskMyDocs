# /playwright-tester

Wrapper concettuale per invocare l'agente o la skill Playwright.

## Parametri tipici

- `mode=`: smoke, critical-path, regression, visual, perf
- `files=`
- `folders=`
- `grep=`
- `tags=`
- `runner=`: local o ci
- `dry-run=`

## Regole

- mai lanciare l'intera suite in silenzio
- partire da un target ristretto
- raccogliere sempre artifact per i fallimenti

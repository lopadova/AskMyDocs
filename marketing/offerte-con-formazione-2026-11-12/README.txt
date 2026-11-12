Rigenerazione con il Python bundled Codex.
1. Eseguire build_content.py: legge il listino centrale revisione02 e produce contenuti e snapshot prezzi.
2. Eseguire render_editorial.py --input marketing/offerte-con-formazione-2026-11-12/contenuti.json --project-root /Users/marco/www/AskMyDocsDev --output marketing/offerte-con-formazione-2026-11-12/AskMyDocs_con_formazione.pdf --qa-dir marketing/offerte-con-formazione-2026-11-12/qa --overwrite
3. Renderizzare con pdftoppm e ispezionare ogni pagina prima di aggiornare la consegna.
Font: Instrument Serif TTF/OFL dal materiale locale ModelsGenerator. Geist da @fontsource-variable/geist in TomoApp/client, istanze WOFF2 400 e 700 normal/italic convertite con fontTools in TTF. I file e le licenze sono in assets/fonts; i font non sono nel commit. Nessun font di fallback.
Le immagini e il marchio restano negli asset pubblici originali del progetto; nessuna modifica. PDF e PNG sono output locali ignorati da Git.

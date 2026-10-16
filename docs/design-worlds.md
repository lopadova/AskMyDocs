# Identità visive, ambientazioni e astrazioni

L'identità grafica è una configurazione dell'intera installazione: landing, accesso e
applicazione leggono lo stesso valore. La modalità personale giorno/notte resta
indipendente: `data-design-theme` seleziona l'identità, `data-theme="light|dark"`
seleziona l'illuminazione e la palette.

## Catalogo

Il catalogo presenta 18 temi in tre categorie. Gli ID sono stabili e devono coincidere tra
`InstallationAppearance::THEMES` in
`app/Services/Appearance/InstallationAppearance.php` e `VISUAL_THEMES` in
`frontend/src/lib/visual-theme.ts`.

| Categoria | ID |
| --- | --- |
| Identità originali (`identity`) | `humanistic`, `tech-pro`, `retro-future`, `dynamic-office`, `luxury` |
| Ambientazioni (`worlds`) | `cartographic`, `polar`, `orbital`, `botanical`, `oceanic`, `newsroom`, `archaeological`, `alpine`, `paper-world`, `blueprint` |
| Astrazioni (`abstract`) | `semantic-orbits`, `prismatic-index`, `ink-flow` |

Ogni voce frontend contiene nome, descrizione, categoria e palette light/dark.
La pagina **Sistema → Aspetto** (`/app/system/appearance`) offre quattro opzioni
di filtro: tutti (`all`), identità originali (`identity`, 5), ambientazioni
(`worlds`, 10) e astrazioni (`abstract`, 3). Categorie e filtro sono metadati
frontend, non ID di tema persistibili. La pagina consente anteprime locali
giorno/notte. L'identità condivisa cambia solo dopo il
salvataggio riuscito; cambiare filtro o anteprima non salva la scelta.

## Configurazione globale

- `GET /api/appearance` espone il tema attivo anche agli ospiti, senza cache.
- `GET /api/system/appearance` e `PUT /api/system/appearance` richiedono il ruolo
  `super-admin`; i token personali richiedono anche l'abilità `appearance:manage`.
- Il salvataggio aggiorna il record con `id = 1` della tabella
  `installation_appearance`, senza dipendere dal tenant corrente.
- `humanistic` è il valore predefinito se manca il record, il valore non è valido
  o la lettura del database fallisce con `QueryException`. L'errore viene segnalato;
  un errore di scrittura continua a essere restituito al chiamante.

Aggiungere un ID alla collezione esistente non richiede nuove rotte o migrazioni.
L'identità non viene salvata come preferenza nel browser; le schede già aperte la
riconciliano quando tornano visibili.

## Immagini editoriali

I tredici nuovi temi (dieci ambientazioni e tre astrazioni) usano tre scene
ciascuno: `knowledge`, `archive` e `community`. Sono 39 scene, ciascuna in due
versioni di illuminazione: giorno e notte. Ogni coppia mantiene composizione,
inquadratura, architettura, persone e oggetti, oppure gli stessi elementi grafici
nei temi astratti. La variante notturna cambia l'illuminazione della scena
diurna, senza ridisegnarla.

I file WebP e AVIF sono pubblicati sotto `public/editorial/themes/{id}/`:

```text
{scene}-day.{webp,avif}           # 1280 × 853 px
{scene}-day-small.{webp,avif}     # 640 × 427 px
{scene}-night.{webp,avif}         # 1280 × 853 px
{scene}-night-small.{webp,avif}   # 640 × 427 px
```

Sono disponibili 24 file per tema: 312 per i tredici nuovi temi, in AVIF e WebP
a due risoluzioni. Rappresentano 39 scene in due illuminazioni. I prompt di
generazione e i riferimenti usati per le varianti notturne sono registrati in
`public/editorial/themes/worlds-manifest.json`. Gli URL pubblici non includono `public`, per esempio
`/editorial/themes/polar/knowledge-day.webp`.

Humanistic conserva la directory storica `/editorial`; le altre quattro identità
originali usano `/editorial/themes/{id}`. I ruoli editoriali dell'applicazione sono
`knowledge`, `dialogue`, `archive`, `connections`, `processes`, `community`, `trust`
e `observatory`. La loro risoluzione verso le scene disponibili è centralizzata in
`getArtworkBase` (`frontend/src/lib/visual-theme.ts`): i consumatori devono passare
il ruolo, senza costruire percorsi o duplicare la mappatura nelle singole pagine.

Nei nuovi temi, `knowledge` e `observatory` usano la scena `knowledge`;
`archive`, `processes` e `trust` usano `archive`; `community`, `dialogue` e
`connections` usano `community`.

Le immagini sono decorative, con testo e controlli leggibili indipendentemente
dallo sfondo. Anteprime esplicite di un'altra ambientazione devono restare locali
al componente e non modificare gli attributi dell'intero documento.

## Checklist di registrazione e verifica

Per ogni nuovo tema:

- Registrare lo stesso ID nell'allowlist backend e nel catalogo frontend con
  categoria `worlds` o `abstract`; aggiungere nome e descrizione alle traduzioni
  dell'Aspetto. Non inserire i nomi delle categorie nell'allowlist dei temi.
- Definire palette e tipografia in `frontend/src/styles/design-worlds.css`,
  verificando anche landing e accesso. I nuovi temi conservano la geometria
  Humanistic; gli adattamenti di bordi e spaziature delle quattro identità
  originali restano in `frontend/src/styles/design-themes.css`. Le anteprime
  annidate devono ripristinare localmente la geometria Humanistic.
- Esportare le tre coppie di scene giorno/notte nelle due risoluzioni; conservare
  riferimenti ai master e all'editing notturno nel registro degli asset utilizzato
  dal progetto.
- Aggiornare la risoluzione centralizzata dei ruoli e verificare che tutti gli
  otto ruoli generino URL esistenti, in entrambe le modalità.
- Verificare parità dei 18 ID, i conteggi 5/10/3 delle tre categorie, le quattro
  opzioni di filtro, anteprime, salvataggio autorizzato,
  rifiuto degli ID sconosciuti e fallback Humanistic.
- Controllare nel browser Herd `https://askmydocsdev.test` landing, accesso,
  workspace e amministrazione in light/dark e a larghezza mobile: nessuna immagine
  mancante, testo leggibile, focus visibile e nessun cambio globale durante
  un'anteprima non salvata.

Comandi mirati da eseguire dalla radice del progetto dopo una modifica:

```sh
php artisan test tests/Feature/Api/InstallationAppearanceTest.php tests/Feature/LandingPageTest.php
npm test -- frontend/src/lib/visual-theme.test.tsx frontend/src/components/editorial/EditorialArtwork.test.tsx frontend/src/features/system-admin/appearance/AppearanceView.test.tsx
npm run typecheck
```

Questa checklist descrive le verifiche richieste; non costituisce un resoconto di
test già eseguiti.

---
description: "Verifica impatto prestazionale di modifiche frontend su Core Web Vitals, bundle, media e risorse third-party."
---

# PageSpeed & Core Web Vitals Review

Usa questa checklist quando modifichi JS, CSS, immagini, font, rendering above-the-fold o script esterni.

## Controlli

### JavaScript
- Caricato solo sulle pagine che lo usano davvero
- Nessun blocco inutile nel `<head>`
- Nessuna libreria pesante per un singolo comportamento banale
- Lazy loading o split se il bundle cresce in modo sensibile

### CSS
- Nessun CSS globale aggiunto per un caso locale
- Nessun `@import` evitabile
- Font con `font-display: swap`
- Nessun layout shift dovuto a font o componenti che entrano tardi

### Immagini e media
- Formato moderno quando possibile
- `width` e `height` espliciti
- `loading="lazy"` sotto la fold
- `srcset` se servono piu' tagli

### Third-party
- `async` o `defer` dove applicabile
- `preconnect` per host critici
- caricamento condizionale e non globale
- chiaro su quali pagine la risorsa e' necessaria

### Rendering
- placeholder o skeleton per ridurre CLS
- niente lavoro pesante sul main thread al load
- attenzione a LCP, INP e TBT per componenti ricchi

## Output atteso

1. Impatto stimato: nessuno, minimo, moderato, significativo
2. Metriche toccate: LCP, CLS, INP, TBT, Speed Index
3. Pagine coinvolte
4. Correzioni suggerite

---
id: DEC-2026-0007
slug: decision-scadenzario-pro
type: decision
status: accepted
project: prometeo-antincendio
owners: [Direzione Tecnica, IT]
tags: [scadenze, software, gestionale, decision]
retrieval_priority: 84
created_at: 2026-02-10
updated_at: 2026-03-15
related: ["[[normativa-cpi]]", "[[incidente-cantiere-aurora]]", "[[rejected-controlli-manuali-carta]]"]
supersedes: ["[[rejected-controlli-manuali-carta]]"]
summary: Adozione del software ScadenzarioPRO per il presidio digitale delle scadenze antincendio.
---

# Decisione: adozione di ScadenzarioPRO

## Contesto

Il presidio delle scadenze antincendio (rinnovo CPI ogni 5 anni, revisione
semestrale e collaudo sessennale degli estintori, aggiornamento formazione) è
un'attività ad alto rischio operativo: una scadenza mancata si traduce in
sanzioni per il cliente e in danno reputazionale per Prometeo.

L'episodio del cliente **Cantiere Aurora** (vedi [[incidente-cantiere-aurora]]),
con CPI scaduto e conseguente sanzione, ha reso evidente che il presidio
cartaceo non era più sostenibile.

## Decisione

Prometeo adotta **ScadenzarioPRO** come software unico di gestione delle
scadenze antincendio per tutti i clienti.

Questa decisione **supersede** l'approccio precedente di gestione manuale su
carta, descritto e motivato in [[rejected-controlli-manuali-carta]].

## Motivazioni

- **Promemoria automatici** con preavviso configurabile per ogni scadenza.
- **Vista centralizzata** di tutte le scadenze per cliente e per tipologia.
- **Tracciabilità** degli interventi (revisioni, collaudi, rinnovi).
- **Riduzione del rischio umano** rispetto alla consultazione manuale di registri
  cartacei.

## Ambito di applicazione

| Scadenza | Origine | Censita in ScadenzarioPRO |
|---|---|---|
| Rinnovo CPI (5 anni) | [[normativa-cpi]] | Sì |
| Revisione estintori (6 mesi) | [[classi-estintori]] | Sì |
| Collaudo estintori (6 anni) | [[classi-estintori]] | Sì |
| Aggiornamento formazione | [[corso-salamandra]] | Sì |

## Conseguenze

Ogni nuovo sopralluogo chiuso su Modulo PA-12 alimenta direttamente le scadenze
in ScadenzarioPRO. La consultazione settimanale dello scadenzario diventa parte
della routine operativa della Direzione Tecnica.

# PLAN — v6.0 AI Act Compliance Admin UI/UX (Package: laravel-ai-act-compliance-admin)

## Obiettivo
Realizzare una dashboard professionale enterprise-grade separata dal core AskMyDocs, montabile su `/admin/ai-act-compliance`, con 8 schermate operative complete per governance AI Act.

## Architettura UI
- Shell SPA: sidebar + topbar + command palette + area contenuto.
- Routing: 8 route top-level (`/`, `/dsar`, `/consent`, `/risks`, `/incidents`, `/bias`, `/dpo`, `/settings`).
- Data layer: axios shared client + query cache per liste/filtri/paginazione.
- Theming: light/dark/system con token semantici per severity/stati.

## Schermate e contratti
1. Overview: KPI, activity feed, trend DSAR, stato attestazioni.
2. DSAR: queue, filtri, dettaglio, azioni stato e SLA.
3. Consent: vista per utente e per feature, revoche e timeline.
4. Risks: inventory, category AI Act, owner, mitigation timeline.
5. Incidents: board per stati + dettaglio escalation.
6. Bias: cohort metrics, drift, alert threshold panel.
7. DPO: governance center, retention/deletion audit, attestation export.
8. Settings: knobs, flags, role matrix, SLA e webhook.

## UX Quality Gates
- Stato completo loading/empty/error/success su tutte le pagine.
- Accessibilità keyboard-first + focus management + landmarks ARIA.
- Performance target: caricamento iniziale percepito <1.5s con skeleton.
- Consistenza visuale: componenti riusabili (table/card/chart/drawer/dialog).

## Test plan UI
- Vitest: componenti critici (tabella filtri, pannelli dettaglio, stato badge).
- Playwright: flussi reali per 8 schermate (happy + errore API + autorizzazioni).
- Regressione visuale minima sulle viste principali (overview/dsar/incidents/bias).

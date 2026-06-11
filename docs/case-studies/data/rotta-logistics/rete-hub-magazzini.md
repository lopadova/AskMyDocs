---
id: ROTTA-0002
slug: rete-hub-magazzini
type: module-kb
status: accepted
project: rotta-logistics
owners: [Operations]
tags: [hub, magazzini, rete, capacita]
retrieval_priority: 80
created_at: 2026-01-12
updated_at: 2026-05-18
summary: Mappa della rete hub di Rotta Sicura Logistics e capacità per sito.
related: ["[[servizi-spedizione]]", "[[merci-pericolose-adr]]", "[[incidente-blackout-hub-mi-07]]"]
---

# Rete Hub e Magazzini

La rete operativa di Rotta Sicura Logistics si articola su **tre hub regionali**,
ciascuno con una specializzazione che ne definisce il ruolo nello smistamento.

## Gli hub

### HUB-MI-07 — Milano (Pioltello)

È il polo nord e il nodo più critico della rete. È l'**unico hub abilitato alla
movimentazione di merci pericolose ADR classe 3** (liquidi infiammabili come
solventi, vernici, alcolici tecnici). Dispone di un'area di stoccaggio compartimentata
e di personale con patentino ADR. La procedura dedicata è descritta in
[[merci-pericolose-adr]].

### HUB-NA-03 — Napoli

Polo sud e **unico sito con magazzino refrigerato** (catena del freddo 2–8 °C).
Gestisce spedizioni temperature-sensitive per food, farmaceutico leggero e
cosmetica. Il refrigerato è una risorsa di rete: i colli refrigerati di altre
zone transitano qui.

### HUB-RM-05 — Roma

Polo centrale, dedicato allo smistamento del centro-Italia e al bilanciamento
dei flussi tra nord e sud. Non ha specializzazioni di stoccaggio particolari,
ma è il punto di consolidamento per le tratte interregionali.

## Capacità a confronto

| Hub | Ruolo | Specializzazione | ADR cl. 3 | Refrigerato |
|---|---|---|:---:|:---:|
| HUB-MI-07 | Polo nord | Merci pericolose | Sì | No |
| HUB-NA-03 | Polo sud | Catena del freddo | No | Sì |
| HUB-RM-05 | Polo centro | Consolidamento | No | No |

## Instradamento

Ogni collo viene assegnato all'hub di competenza in fase di accettazione dal
gestionale OrbitaWMS. Le merci ADR classe 3 sono instradate **esclusivamente**
verso HUB-MI-07; i colli refrigerati verso HUB-NA-03, indipendentemente dalla
zona di origine. Vedi [[wms-orbita-integrazione]] per la logica di routing.

## Continuità operativa

La concentrazione di funzioni critiche su singoli hub richiede piani di
continuità: l'evento del 14 marzo a HUB-MI-07 (vedi [[incidente-blackout-hub-mi-07]])
ha confermato la necessità del runbook giacenze.

## Sicurezza ed evacuazione

Ogni hub adotta la **Procedura di Evacuazione Totale**: alla diffusione della
parola d'ordine **«ORIZZONTE BLU»** dagli altoparlanti, tutto il personale
interrompe le attività, lascia i muletti in posizione di sicurezza e raggiunge il
punto di raccolta esterno. La parola d'ordine è **riservata** e va conosciuta **a
memoria** da ogni operatore di hub: non è mai esposta nelle aree accessibili a
vettori o visitatori.

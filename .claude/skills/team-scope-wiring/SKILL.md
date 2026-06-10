---
name: team-scope-wiring
description: How to wire an admin SPA page (or the chat) to the global TEAM (= tenant) switcher in the topbar — the X-Tenant-Id header is automatic via the shared axios client, switching team clears the TanStack cache and remounts the route outlet, project options must come from tenant-scoped endpoints. Trigger when wiring an admin page to the team scope, when adding a NEW admin page or feature folder under frontend/src/features/, when touching a project picker / project filter, or when a page shows stale cross-team data after a team switch.
---

# Team-scope wiring — cablare una pagina al team switcher

## Architettura (cosa è già automatico)

Il menu in alto nella topbar è il **TeamSwitcher** (`frontend/src/components/shell/TeamSwitcher.tsx`).
Team = tenant. La selezione vive in `frontend/src/lib/team-store.ts`
(Zustand, persistito `{userId, currentTeam}` su localStorage) e viene
sincronizzata da `useAuthStore.setMe` a ogni bootstrap/login leggendo la
chiave `teams` di `GET /api/auth/me` (costruita da
`app/Services/Auth/UserTeamsResolver.php`).

Tre meccanismi rendono il cablaggio "gratis" per la maggior parte delle pagine:

1. **Header automatico** — l'interceptor in `frontend/src/lib/api.ts`
   timbra `X-Tenant-Id` su ogni richiesta dell'axios condiviso (esclusi
   `/api/auth/`, `/sanctum/`, `/testing/`). Lato BE `ResolveTenant` lo
   risolve nel `TenantContext`; `AuthorizeTenantHeader` autorizza via
   membership (`project_memberships` nel tenant) o `tenant.cross-access`.
2. **Cache flush** — `switchTeam()` fa `queryClient.cancelQueries()` +
   `queryClient.clear()`: ogni query montata rifetcha sotto il nuovo team.
3. **Remount keyed** — in `AppShell` l'`<Outlet />` è dentro un div
   `key={currentTeam}`: lo stato locale di pagina (picker, selezioni,
   filtri free-text) si azzera da solo al cambio team.

## Checklist per pagina (in ordine)

1. **Le chiamate passano dall'axios condiviso?** Un `fetch` /
   `EventSource` raw NON riceve l'header: replicarlo a mano come fa
   `frontend/src/features/chat/use-chat-stream.ts` (accanto a
   `X-XSRF-TOKEN`). Grep: `fetch(` e `new EventSource` nel feature folder.
2. **Il controller BE è tenant-scoped?** Ogni query su tabella
   tenant-aware deve avere `forTenant()` / `where('tenant_id', …)` (R30,
   vedi [[cross-tenant-isolation]]). Se manca, fixare il BE PRIMA di
   cablare il FE — il cambio team che "non cambia niente" di solito è un
   controller che aggrega tutti i tenant.
3. **Le `Cache::remember` BE includono il tenant nella chiave?**
   Esempio canonico: `DashboardMetricsController::cacheKey()` →
   `admin.metrics.{kind}.{tenant}.{project}.{days}`. Una chiave senza
   tenant serve i numeri del team A al team B per tutta la TTL.
4. **Le opzioni progetto derivano da un endpoint tenant-scoped?** (R18)
   Mai liste literal: il picker di KbView usa `GET /api/admin/kb/projects`
   (già `forTenant`) e si aggiorna da solo. Per i progetti A CUI L'UTENTE
   HA ACCESSO nel team attivo: `useTeamStore` → team corrente → `projects`.
5. **Lo stato locale vive sotto l'Outlet keyed?** `useState` dentro la
   pagina si resetta gratis. Stato in store globali (Zustand/context) o
   nell'URL sopravvive al remount → serve un reset esplicito al cambio
   team (subscribe su `currentTeam`) o va spostato in stato locale.
6. **La pagina è volutamente globale?** (Roles/Permissions, PII strategy,
   application log, activity, failed jobs, queue) → NON cablarla: aggiungi
   il chip "global" con tooltip (pattern in
   `frontend/src/features/admin/logs/LogsView.tsx`) e documenta il perché.
7. **Test.** PHPUnit: fixture su 2 tenant + assert di esclusione per il
   controller (pattern: `AdminMetricsServiceTest::test_every_metric_excludes_rows_from_other_tenants`).
   E2E: scenario di switch su tenant `acme` (seedato da `DemoSeeder`)
   in `frontend/e2e/team-switcher.spec.ts` o spec dedicata — i conteggi
   default/acme sono volutamente diversi (R16).

## Anti-pattern

- ❌ **Tenant nelle query key di TanStack** invece del clear globale:
  l'header è implicito in ogni richiesta; chiavi parziali = cache miste.
- ❌ **Header su `/api/auth/*`**: un team persistito non più valido
  fa 403 sul bootstrap e locka l'utente fuori. Le esenzioni vivono in
  `TENANT_EXEMPT_PREFIXES` (`frontend/src/lib/api.ts`).
- ❌ **Liste tenant/progetti hardcoded nel FE** (R18) — i seed
  `PROJECTS` in `frontend/src/lib/seed.ts` sono UI-only legacy.
- ❌ **Catch silenzioso del 403 `tenant_forbidden`**: l'interceptor
  resetta il team MA propaga l'errore (R14) — non aggiungere retry
  silenziosi a valle.
- ❌ **Nuova pagina con picker progetto proprio scollegato dal team** —
  le opzioni devono sempre arrivare da un endpoint tenant-scoped, così
  il remount le riallinea da solo.

## Dati di test

`DemoSeeder` seeda due tenant: `default` (3 docs, 5 chat) e `acme`
(label "Acme Corp", 2 docs, 2 chat, membership solo per
`admin@demo.local`; `viewer@demo.local` resta single-team — serve al
failure path). I conteggi diversi sono il segnale differenziante: non
pareggiarli mai.

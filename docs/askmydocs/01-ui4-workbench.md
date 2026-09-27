---
title: UI4 Workbench
status: active
---

# UI4 Workbench

The workbench is available only in the AskMyDocs Dev host at
`https://askmydocsdev.test/workbench`. It is a standalone surface, outside the
dashboard shell. Its signed-in header carries one AskMyDocs mark in the
Workbench's own brand position; a guest sees that mark and one access button. It
uses the existing authenticated session, selected tenant, and Laravel
CSRF cookie. It does not provide a guest/demo route or a second voice agent.

## Linked packages

The host consumes local path packages through symlinks:

- PHP: `ui4/laravel-workbench` → `/Users/marco/packages/Ui4VocalAgent/packages/laravel-ui4-workbench`
- React: `@ui4/workbench-react` → `/Users/marco/packages/Ui4VocalAgent/packages/workbench-react`

`composer.json` declares the PHP path repository with `symlink: true`; `package.json`
declares the React package through `file:`. The host Vite configuration allows the
linked source and de-duplicates React/React DOM so the workbench shares the host
React runtime.

## API and persistence boundary

The package API is mounted at `/api/workbench` with `web`, `auth:sanctum`, and
`tenant.authorize` middleware. The React transport requests `/sanctum/csrf-cookie`
before workbench traffic, sends credentials, and derives `X-XSRF-TOKEN` and
`X-Tenant-Id` for every request at request time. A `401`, `403`, or `419` is shown
as an actionable access/session state; a `419` refreshes the CSRF cookie but does
not replay a write automatically.

`Ui4WorkbenchScope` derives a signed identity from the authenticated user and active
tenant. The authorizer, channel authorizer, and host record adapter all verify that
scope. Generic objects created by the workbench are stored in
`ui4_workbench_sandbox_objects`, keyed by tenant, user, and type. UI4 records,
operations, relations, layouts, and event history stay in the package tables; the
adapter re-hydrates only host objects belonging to the current scope. Consequently,
records cannot be read across a user or tenant boundary.

The package's operation idempotency uniqueness is `(actor, idempotency_key)`, so
two users may use the same client key without sharing an operation. Reusing a key
with a different request fingerprint is rejected.

## Realtime and voice limits

This integration uses the package polling recovery endpoint only. AskMyDocs Dev has
no verified UI4 websocket/broadcast client in this integration, so no Reverb/Echo
configuration has been added. UI4 voice is explicitly disabled: there is no UI4
OpenAI key, no voice control, and no live OpenAI request path.

## Local development

Apply Dev migrations from this repository only:

```sh
php artisan migrate --no-interaction
```

The linked workbench package requires Node 24.15 or newer. When the shell default
is older, use the temporary Node 24 runner for host checks and Vite:

```sh
npm exec --yes --package=node@24 -- sh -c 'npm run typecheck'
npm exec --yes --package=node@24 -- sh -c 'npm run build'
npm exec --yes --package=node@24 -- sh -c 'npm run dev'
```

After editing the React package source, rebuild its distributable from the UI4
repository before reloading the host:

```sh
cd /Users/marco/packages/Ui4VocalAgent
npm run build:workbench
# for iterative package work
npm run watch:workbench
```

Open the Herd site, not a replacement `artisan serve` address:
`https://askmydocsdev.test`.

## Verification

Run the focused host API coverage:

```sh
php artisan test tests/Feature/Ui4WorkbenchApiTest.php
```

It covers anonymous rejection; generic object persistence; proposal confirmation;
semantic relations; layout save/reload; user/tenant isolation; and independent
idempotency keys for two users. The source package also has its own focused tests
for operation idempotency and host-adapter behavior.

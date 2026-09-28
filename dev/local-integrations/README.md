# Local connector fixtures

This directory is a self-contained, deterministic development target for the
three case-study tenants. It starts two loopback-only Node services over one
checked-in dataset:

- **Static API** — `http://127.0.0.1:4310`
- **Streamable HTTP MCP** — `http://127.0.0.1:4311/mcp/{company_key}`

It never contacts Gmail, a database, or any external service. `dataset.mjs` is
the single source of truth for the company keys, case-study account identities,
mailbox keys, expected document IDs and operational records. The identifiers
are deliberately correlated with the existing Laravel case-study seeders:

| Tenant / project key | Mailbox keys | API + MCP records |
| --- | --- | --- |
| `rotta-logistics` | `rotta-logistics-1`, `rotta-logistics-2` | shipments |
| `prometeo-antincendio` | `prometeo-antincendio-1`, `prometeo-antincendio-2` | safety inspections and supply orders |
| `passolibero-calzature` | `passolibero-calzature-1`, `passolibero-calzature-2` | purchase orders and returns |

## Complete local scenario

From the repository root, this one command rebuilds the scenario visible after
each case-study login:

```bash
php artisan dev:reset-local-integration-fixtures
```

It is guarded by `APP_ENV=local` and resets **only** the three case-study
tenants. It starts these services, recreates users and Markdown documents,
refreshes the dedicated Gmail fixture dataset, ingests those messages inline
(without draining a shared Redis queue), then creates the trusted company
retrieval profile plus the scoped API and MCP connections, and runs read-only
MCP smoke checks. The profile is already configured for each case study, so a
case-study admin can use recursive search immediately without manual setup.

The command shows a six-stage progress bar. During IMAP delivery it also shows
one progress bar per mailbox with messages sent/total, delivery rate and ETA,
so a long reset remains observable without reading the verbose logs.

The command writes the explicit local-only transport flag to `.env` and clears
the derived configuration cache. The production API and MCP guards otherwise
correctly reject loopback endpoints.

For API/MCP development without touching the dedicated Gmail fixture mailbox:

```bash
php artisan dev:reset-local-integration-fixtures --without-email
```

The default `gold` email profile is the quicker functional scenario;
`--email-profile=demo` selects the larger deterministic corpus.

## Lifecycle

From the repository root:

```bash
dev/local-integrations/local-integrations.sh start
dev/local-integrations/local-integrations.sh status
dev/local-integrations/local-integrations.sh restart
dev/local-integrations/local-integrations.sh stop
```

The launcher stores PID files and logs in the ignored
`dev/local-integrations/.runtime/` directory. Before sending a signal it checks
that the PID belongs to the expected fixture script, so it will not kill an
unrelated Node process. Each Node server is detached from the launcher before
it starts, so it remains available after the Artisan command finishes.
`restart` stops and starts both servers. Both services
bind only to `127.0.0.1` by default; `LOCAL_INTEGRATIONS_HOST` may only be
`127.0.0.1`, `::1`, or `localhost`.

Optional overrides:

```bash
LOCAL_INTEGRATIONS_API_PORT=4310 \
LOCAL_INTEGRATIONS_MCP_PORT=4311 \
dev/local-integrations/local-integrations.sh start
```

## Local service console

After signing in locally as an `admin` or `super-admin`, open:

```text
https://askmydocsdev.test/app/developer/local-integrations
```

The app resolves the active team automatically. The console is served only when
`APP_ENV=local`; it can start, stop, or restart both Node fixtures, polls their
status every two seconds, and shows a 60-second activity chart plus recent
request metadata. For MCP calls it also shows the parsed JSON-RPC request and
response, retained only in memory for the current process (up to 120 events,
with each response capped at 32 KB). It never records headers or credentials;
sensitive JSON fields are redacted. Health and monitor polls are intentionally
omitted from the activity view.

## API contract

Health is available at `GET /health`. The API intentionally has no unscoped
operational-record endpoint; every record request includes its company key:

```text
GET /v1/companies
GET /v1/companies/{company_key}/context
GET /v1/companies/{company_key}/records?kind={kind}&status={status}
GET /v1/companies/{company_key}/records/{record_id}
```

Unknown companies and records outside the selected company return `404`. This
is a local mock, not an authorization boundary: the production connector still
has to bind the configured endpoint to the current tenant and project.

## MCP contract

The MCP endpoint is stateful Streamable HTTP and has a health endpoint at
`GET /health`. It uses the installed `@modelcontextprotocol/sdk`. Configure a
tenant connector with its dedicated endpoint, for example
`POST /mcp/rotta-logistics`; the endpoint itself fixes the company boundary and
the tools do not accept a second company key. All four tools are read-only:

- `get_company_context`
- `list_operational_records`
- `get_operational_record`
- `search_operational_records`

The MCP connector configuration should point each tenant at its own path:
`http://127.0.0.1:4311/mcp/{tenant_id}`. A generic `POST /mcp` is also present
for protocol exploration and requires `company_key` on every tool call; do not
use it for tenant connector configuration. The Artisan command above creates
one API and one MCP connector per tenant and seeds matching email/document
identifiers from `dataset.mjs`. It includes narrowly scoped compatibility for
the installed PHP MCP transport: it supplies the Streamable HTTP `Accept`
values and translates only an empty legacy parameter list into the MCP object
form. All current SDK clients use the standard session flow unchanged.

## Smoke test

```bash
node --test dev/local-integrations/test/smoke.test.mjs
```

The test starts both services on ephemeral loopback ports, checks the health
endpoints and exercises a real Streamable HTTP MCP client. No persistent
processes, database rows, or external calls are created.

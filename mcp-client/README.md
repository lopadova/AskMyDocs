# AskMyDocs MCP Client Sidecar

Node.js + TypeScript sidecar that bridges the AskMyDocs Laravel host to
external **MCP (Model Context Protocol) servers** over the three transports
the [official SDK](https://github.com/modelcontextprotocol/typescript-sdk)
supports — `stdio`, `sse`, and HTTP `streamable`. The sidecar exposes a
minimal HTTP API on `localhost:3535` consumed by the Laravel
`App\Mcp\Client\McpClientBridge`.

This component ships as part of the AskMyDocs v5.0 Agentic Platform (see
[`docs/v4-platform/PLAN-v5.0-agentic-platform-mcp-client.md`](../docs/v4-platform/PLAN-v5.0-agentic-platform-mcp-client.md)
and `ADR 0011 — Node sidecar architecture`).

## Why a Node sidecar (and not pure PHP)?

- The official MCP TypeScript SDK is the only first-class client implementation
  — breaking spec changes are covered automatically by upstream.
- **All three transports** are required: stdio servers (~60% of the reference
  ecosystem), SSE (web-based), and streamable HTTP. Stdio in PHP is non-trivial
  and would force us to fork the spec.
- 50+ reference MCP servers are TypeScript/Node — testing against real servers
  is easier on the same runtime.

The trade-off (two runtimes in production) is mitigated by a single-Dockerfile
deployment, supervisord-managed process supervision, and localhost-only bind
(no public network surface).

## Repository layout

```
mcp-client/
├── package.json
├── tsconfig.json
├── tsconfig.test.json
├── jest.config.js
├── Dockerfile
├── supervisord.conf
├── src/
│   ├── server.ts             # HTTP API on :3535
│   ├── auth.ts               # Internal Sanctum token middleware
│   ├── logging/
│   │   └── logger.ts         # JSON line logger
│   ├── clients/
│   │   ├── McpClientBase.ts
│   │   ├── StdioMcpClient.ts
│   │   ├── SseMcpClient.ts
│   │   ├── StreamableHttpMcpClient.ts
│   │   └── factory.ts
│   ├── registry/
│   │   ├── ToolRegistry.ts
│   │   └── CredentialResolver.ts
│   └── types/mcp.ts          # Zod schemas + TypeScript types
├── tests/
│   ├── unit/                 # Jest unit tests (auth, schemas, registry, server)
│   ├── integration/          # End-to-end against a stdio fixture
│   └── fixtures/
│       └── fake-mcp-server.ts
└── README.md
```

## HTTP API

All endpoints accept JSON. All endpoints (except `/healthz`) require a
`Authorization: Bearer <token>` header matching `MCP_SIDECAR_INTERNAL_TOKEN`.

### `GET /healthz`

Returns `{ status: 'ok', version, uptime_s }`. Used by Laravel
`McpClientBridge::isHealthy()` and Docker `HEALTHCHECK`.

### `POST /handshake`

```json
{
  "tenant_id": "tenant-1",
  "server_id": 42,
  "server_name": "github",
  "transport": "stdio",
  "endpoint": "npx -y @modelcontextprotocol/server-github",
  "auth_config": { "token": "ghp_xxx" }
}
```

Returns:

```json
{
  "ok": true,
  "protocol_version": "2024-11-05",
  "server_info": { "name": "github", "version": "1.2.3" },
  "capabilities": { "tools": {} },
  "tools": [{ "name": "list_repositories", "description": "...", "inputSchema": {...} }],
  "resources": [],
  "duration_ms": 412
}
```

### `POST /invoke-tool`

```json
{
  "tenant_id": "tenant-1",
  "server_id": 42,
  "server_name": "github",
  "transport": "stdio",
  "endpoint": "npx -y @modelcontextprotocol/server-github",
  "tool_name": "list_repositories",
  "tool_input": { "owner": "lopadova" }
}
```

Returns:

```json
{ "ok": true, "status": "ok", "result": {...}, "duration_ms": 1024 }
```

On failure: `status` is one of `error` / `timeout` / `denied` and `error` is a
string description.

### `POST /invalidate`

Closes the cached client for `(tenant_id, server_id)` and forces a fresh
connection on the next call. Used when the operator changes a server's
endpoint or credentials.

## Configuration

| Env var | Default | Notes |
|---|---|---|
| `MCP_SIDECAR_PORT` | `3535` | TCP port |
| `MCP_SIDECAR_BIND` | `127.0.0.1` | Bind address; **never** expose publicly |
| `MCP_SIDECAR_LARAVEL_URL` | `''` | Laravel base URL for credential callback (e.g. `http://laravel:8000`) |
| `MCP_SIDECAR_INTERNAL_TOKEN` | `''` | Shared secret rotated each deploy; matches Laravel `config('mcp.sidecar.internal_token')` |
| `MCP_SIDECAR_DEFAULT_TIMEOUT_MS` | `30000` | Per-operation timeout |
| `MCP_SIDECAR_MAX_CONCURRENT` | `32` | Reserved for future flow control |
| `MCP_SIDECAR_LOG_LEVEL` | `info` | `debug` / `info` / `warn` / `error` |

The credential resolver caches `auth_config` payloads from Laravel for 30s by
default. When the sidecar caller already includes a non-empty `auth_config`,
the resolver is skipped — useful for tests and one-off invocations.

## Running locally

```bash
cd mcp-client
npm install
npm run dev    # tsx watch on src/server.ts
```

Health check:

```bash
curl http://127.0.0.1:3535/healthz
```

## Tests

```bash
npm test                  # all (unit + integration)
npm run test:unit         # fast loop
npm run test:integration  # requires npx + node availability
```

Integration tests spawn a stdio MCP fixture (`tests/fixtures/fake-mcp-server.ts`)
via the official SDK and round-trip `tools/list` + `tools/call` through the
real HTTP API.

## Docker

The Dockerfile is multi-stage: a `builder` stage produces `dist/`, a `runtime`
stage runs `supervisord` which keeps `node dist/server.js` up with
auto-restart. Use the published image:

```bash
docker run --rm -p 3535:3535 \
  -e MCP_SIDECAR_INTERNAL_TOKEN="<shared-with-laravel>" \
  -e MCP_SIDECAR_LARAVEL_URL="http://host.docker.internal:8000" \
  ghcr.io/lopadova/askmydocs-mcp-client:v5.0.0
```

## Security boundary

- Bind defaults to `127.0.0.1` — the sidecar is **not** intended to be exposed.
  When containerised, only the Laravel container should be able to reach it.
- Every authenticated request is verified against `MCP_SIDECAR_INTERNAL_TOKEN`
  via a timing-safe comparison.
- Credentials never persist inside the sidecar — they're fetched on demand
  from Laravel via the `/api/mcp/credentials` callback and cached in memory
  for 30s.
- The sidecar process runs as the unprivileged `mcp` user inside the Docker
  image.

## License

MIT.

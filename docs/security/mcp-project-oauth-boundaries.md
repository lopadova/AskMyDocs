# MCP project OAuth and ingestion boundaries

The project OAuth server uses a separate MCP resource URL per connection.
The OAuth session authority remains `OAuthController`; it is not duplicated.

| Path | Data/effect | Authorization and limits | Executable evidence |
| --- | --- | --- | --- |
| OAuth registration/consent | Client registration and a tenant/project grant | Public clients only, callback validation, independent registration throttle, S256 PKCE, explicit session/CSRF approval, exact membership, immutable project binding | `McpOAuthConnectionTest`, existing `OAuthTest` |
| MCP and REST retrieval | Selected project's indexed sources | Exact token resource, live user/client/membership checks, explicit tenant/project query filters, existing document scope, bounded output, audit before execution | `McpOAuthConnectionTest`, architecture route exposure tests |
| Import preparation | Private staged files | `mcp:ingest`, admin/super-admin, unrestricted project membership, ten-file/5 MB/10 MB caps, filename and magic-byte checks, server manifest, cache/row lock and DB uniqueness | `McpOAuthConnectionTest`, two-process `McpImportConcurrencyTest` |
| Browser import approval | Indexing the exact staged file set | Session-only endpoint, CSRF, owner and current grant recheck, opaque import ID, one-hour expiry, byte-hash comparison, transactional reservation and fail-closed audit | `McpOAuthConnectionTest`, `McpPages.test.tsx` |
| Queue execution | Conversion, PII handling, embeddings and document persistence | Shared `IngestDocumentJob`, trusted import metadata, initiating identity and permissions reloaded before execution; metadata stripped at untrusted ingestion boundaries | `McpOAuthConnectionTest`, upload integration tests, `OcrServiceMetadataTest` |
| Refresh/revocation | Credential rotation or loss of access | Hashed refresh tokens, grant-family lock, rotation, replay revocation, fixed 90-day family limit, current project membership and client scopes | `McpOAuthConnectionTest` |

The role matrix for the new surfaces is:

| Operation | Exact authority |
| --- | --- |
| Create/download a connection package | Active signed-in account; package owner only |
| Select company/project in consent | Active signed-in account with membership in that exact company/project |
| Read the connected project | Valid project-bound OAuth grant and current membership; ordinary PATs and browser cookies do not authorize MCP |
| Prepare/approve/process an import | Same grant plus `mcp:ingest`, admin or super-admin, unrestricted selected-project membership; browser approval additionally requires the initiating user's session |

Authentication and plugin bootstrap are deliberately not exposed as MCP
mutations. The agent must not grant itself access, choose a new audience or
approve its own import. Retrieval/import services have PHP, HTTP and MCP
adapters; browser confirmation stays session-only by design (SEC-AI-ACT-001).

Infrastructure verification remains distinct: a public plugin requires a
deployed HTTPS origin and OpenAI directory review. A local package, test pass or
Herd endpoint does not prove public publication, production TLS/edge policy or
the eventual user's account authorization.

# MCP Debugger Playbook (AskMyDocs v8.0/W7.4)

This runbook shows how to connect a consumer workspace (Claude Code) to AskMyDocs MCP debugger tools.

## 1. Mint tenant token in AskMyDocs admin

1. Open `/app/admin/mcp/tokens`.
2. Create a token for the target tenant with the required scopes:
   - `mcp:read` for read tools
   - `mcp:tools:propose` for propose-only tools
3. Copy the plaintext token (shown once).

## 2. Generate `.mcp.json` snippet

Run in AskMyDocs host workspace:

```bash
php artisan askmydocs:mcp:connect \
  --server=https://askmydocs.example.com \
  --tenant=acme \
  --token=YOUR_TOKEN_VALUE
```

The command prints a ready-to-paste JSON block for consumer `.mcp.json`.

## 3. Paste into consumer workspace

In the consumer repo, create/update `.mcp.json` with the printed `mcpServers.askmydocs` entry.

## 4. Smoke test

From MCP inspector / Claude tool list:

1. Call `kblistdanglingwikilinks` (or equivalent displayed tool name) with:
   - `project_key`: target project
   - `limit`: `5`
2. Confirm non-error JSON response.
3. Confirm AskMyDocs writes an audit row:
   - table: `kb_canonical_audit`
   - `event_type = mcp_tool_invoked`
   - `metadata_json.tool_name` present

## 5. Fast triage

- `mcp_token_required` / `mcp_token_invalid`: token missing/wrong.
- `mcp_token_revoked` / `mcp_token_expired`: rotate token in admin panel.
- `mcp_tenant_mismatch`: `X-Tenant-Id` does not match token tenant.
- `mcp_scope_missing`: token lacks required scope for the tool.


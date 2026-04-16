# Come esporre la KB a Claude via Laravel MCP

## Obiettivo

Fare in modo che Claude non legga direttamente il DB Laravel, ma interroghi il servizio Laravel attraverso un **server MCP HTTP**.

## Scelta consigliata

### Server MCP web
Usa `laravel/mcp` e registra il server con:

- route web MCP
- auth via Sanctum o Passport
- tool read-only e idempotent

## Flusso

1. Laravel espone `/mcp/kb`
2. Claude si connette a quell'endpoint MCP HTTP
3. Claude vede i tool:
   - `kb_search`
   - `kb_read_document`
   - `kb_read_chunk`
   - `kb_recent_changes`
   - `kb_search_by_project`
4. Claude decide quando chiamarli in base a `CLAUDE.md` e alle regole modulari

## Registrazione in Claude

Esempio da terminale del repo applicativo:

```bash
claude mcp add --transport http kb http://kb-internal.example.com/mcp/kb
```

Se usi auth bearer, aggiungi il meccanismo richiesto dalla tua installazione Claude / MCP policy.
Se scegli OAuth con Passport, configura il flusso OAuth del server MCP Laravel.

## Perché questa strada

- la KB resta centralizzata e shared
- Claude non ha bisogno di avere tutta la KB locale
- la query è on-demand
- puoi applicare ACL e filtri lato server
- puoi fare logging, reranking e rate limiting lato Laravel

<?php

declare(strict_types=1);

namespace App\Mcp\Adapters;

use App\Models\McpServer;
use Illuminate\Support\Facades\Crypt;
use Padosoft\AskMyDocsMcpPack\Contracts\McpServerContract;

/**
 * v7.0/W6.3 — adapter from the host's `App\Models\McpServer`
 * Eloquent row to the package's `McpServerContract` shape.
 *
 * The host stores credentials encrypted in `auth_config_encrypted`
 * (Laravel `Crypt`); the package's transport factory expects them
 * decrypted in `transportConfig()`. The adapter decrypts on-demand
 * and merges them with the endpoint into the per-transport keys
 * the package recognises (`endpoint` + `headers` for http / sse,
 * `command` + `args` + `env` for stdio).
 *
 * Decryption failures (key rotation, corrupt blob) degrade
 * gracefully to an empty auth map so a single misconfigured row
 * cannot crash the orchestrator — the upstream MCP server will
 * reject the call instead, which surfaces a clean
 * `McpTransportException` to the audit trail.
 */
final class McpServerAdapter implements McpServerContract
{
    public function __construct(
        private readonly McpServer $server,
    ) {}

    public function id(): string
    {
        // `McpServerContract::id()` is documented as scoped per
        // tenant. The host's primary key is an int; cast to string
        // so the contract's string identity is preserved across the
        // transport layer (e.g. cache keys, audit rows).
        return (string) $this->server->id;
    }

    public function name(): string
    {
        return (string) $this->server->name;
    }

    public function transport(): string
    {
        return (string) $this->server->transport;
    }

    public function tenantId(): ?string
    {
        $tenant = $this->server->tenant_id;
        return is_string($tenant) && $tenant !== '' ? $tenant : null;
    }

    /** @return array<string,mixed> */
    public function transportConfig(): array
    {
        $auth = $this->decryptAuthConfig();

        return match ($this->server->transport) {
            McpServer::TRANSPORT_HTTP, McpServer::TRANSPORT_SSE => [
                'endpoint' => (string) $this->server->endpoint,
                'headers' => $this->extractHeaders($auth),
            ],
            McpServer::TRANSPORT_STDIO => $this->stdioConfig($auth),
            default => [
                'endpoint' => (string) $this->server->endpoint,
            ],
        };
    }

    /**
     * The package contract treats `[]` as "all tools the server
     * advertises" (wildcard). The host's own wildcard sentinel is
     * `enabled_tools_json = ['*']`, so we flatten that to the
     * package vocabulary.
     *
     * Note on the unconfigured-tools case: rows with `null` or `[]`
     * `enabled_tools_json` are filtered out by
     * {@see EloquentMcpServerRegistry::forTenant()} BEFORE they ever
     * reach this adapter, so the `! is_array(...)` branch below is a
     * defence-in-depth guard for direct constructor calls (tests,
     * fixtures). The branch returns `[]` — matching the package
     * contract — but it should be unreachable through the normal
     * orchestrator path; if a fresh-from-DB row hits it, the registry
     * filter has regressed.
     *
     * @return array<int,string>
     */
    public function allowedTools(): array
    {
        $tools = $this->server->enabled_tools_json;
        if (! is_array($tools)) {
            return [];
        }
        // `['*']` is the host's "all tools" sentinel — the package
        // contract treats an empty array the same way, so flatten.
        if ($tools === ['*']) {
            return [];
        }
        return array_values(array_filter(
            array_map(
                static fn($t): string => is_string($t) ? $t : '',
                $tools,
            ),
            static fn(string $t): bool => $t !== '',
        ));
    }

    public function isEnabled(): bool
    {
        return $this->server->status === McpServer::STATUS_ACTIVE;
    }

    /**
     * Tokenise a shell-style command line into argv. Handles:
     *   - bare tokens (`/bin/foo --plain`)
     *   - mid-token double-quoted regions (`--prefix="hello world"`)
     *   - whole-token double-quoted regions (`"with spaces"`)
     *
     * The state machine collects characters into a buffer, flushing
     * on a top-level whitespace and toggling on `"`. Quotes inside
     * a token are stripped so `--arg="hello world"` flushes as the
     * single token `--arg=hello world`. POSIX shell escapes
     * (backslash-spaces, single quotes, env-substitution) are NOT
     * handled — operators who need them should store `command` +
     * `args` separately in a future v8 schema enhancement.
     *
     * @return array<int, string>
     */
    private static function tokeniseCommandLine(string $cmd): array
    {
        $cmd = trim($cmd);
        if ($cmd === '') {
            return [];
        }
        $parts = [];
        $buf = '';
        $inQuotes = false;
        $len = strlen($cmd);
        for ($i = 0; $i < $len; $i++) {
            $ch = $cmd[$i];
            if ($ch === '"') {
                $inQuotes = ! $inQuotes;
                continue; // drop the quote character itself
            }
            if (! $inQuotes && ctype_space($ch)) {
                if ($buf !== '') {
                    $parts[] = $buf;
                    $buf = '';
                }
                continue;
            }
            $buf .= $ch;
        }
        if ($buf !== '') {
            $parts[] = $buf;
        }
        return $parts;
    }

    /** @return array<string,mixed> */
    private function decryptAuthConfig(): array
    {
        $cipher = $this->server->auth_config_encrypted;
        if (! is_string($cipher) || $cipher === '') {
            return [];
        }
        try {
            $decoded = json_decode(Crypt::decryptString($cipher), true);
        } catch (\Throwable) {
            return [];
        }
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string,mixed>  $auth
     * @return array<string,string>
     */
    private function extractHeaders(array $auth): array
    {
        $headers = $auth['headers'] ?? [];
        if (! is_array($headers)) {
            return [];
        }
        $out = [];
        foreach ($headers as $name => $value) {
            if (is_string($name) && (is_string($value) || is_numeric($value))) {
                $out[$name] = (string) $value;
            }
        }
        // Common pattern: a bare `token` in auth_config maps to a
        // standard `Authorization: Bearer <token>` header. Hosts
        // that need a custom shape can store `headers.Authorization`
        // explicitly instead.
        //
        // Two guards over the naive synthesis:
        //   1. The token must be a non-empty string after trimming —
        //      a bogus `"Authorization: Bearer "` header (empty / pure
        //      whitespace token) is worse than no header at all: it
        //      tells the upstream "I authenticated" while carrying no
        //      credential, which masks the real config error.
        //   2. The "do not overwrite an explicit Authorization" check
        //      is case-insensitive — HTTP header names are
        //      case-insensitive per RFC 7230 §3.2, so an operator who
        //      stores `headers.authorization` (lowercase) MUST NOT
        //      end up with both `authorization` AND a synthesized
        //      `Authorization` going on the wire.
        $token = $auth['token'] ?? null;
        if (is_string($token) && trim($token) !== '' && ! self::hasAuthorizationHeader($out)) {
            $out['Authorization'] = 'Bearer ' . $token;
        }
        return $out;
    }

    /** @param  array<string,string>  $headers */
    private static function hasAuthorizationHeader(array $headers): bool
    {
        foreach (array_keys($headers) as $name) {
            if (is_string($name) && strcasecmp($name, 'Authorization') === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param  array<string,mixed>  $auth
     * @return array<string,mixed>
     */
    private function stdioConfig(array $auth): array
    {
        // For stdio servers the host stores the command line in
        // `endpoint` (shell-style). `tokeniseCommandLine()` (a small
        // state machine — NOT a regex) handles three shapes that
        // operators commonly write:
        //
        //   /bin/foo --plain                  (bare tokens)
        //   /bin/foo --prefix="hello world"   (mid-token quote)
        //   /bin/foo "with spaces" --plain    (whole-token quote)
        //
        // Bare `preg_split('/\s+/')` shreds the first two; bare
        // `str_getcsv(...)` only handles the third (quotes must
        // start the field). The state machine collects characters
        // into a buffer, flushes on top-level whitespace, and toggles
        // an in-quotes flag on `"`; quotes themselves are dropped so
        // `--prefix="hello world"` flushes as the single token
        // `--prefix=hello world`. Operators who need POSIX shell
        // escapes (backslash-spaces, single quotes, env-substitution)
        // should store `command` + `args` separately — a future v8
        // enhancement.
        $parts = self::tokeniseCommandLine((string) $this->server->endpoint);
        $command = array_shift($parts) ?? '';

        $env = $auth['env'] ?? [];
        if (! is_array($env)) {
            $env = [];
        }
        return [
            'command' => $command,
            'args' => $parts,
            'cwd' => isset($auth['cwd']) && is_string($auth['cwd']) ? $auth['cwd'] : null,
            'env' => $env,
        ];
    }
}

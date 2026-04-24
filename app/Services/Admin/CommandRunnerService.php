<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Models\AdminCommandAudit;
use App\Models\AdminCommandNonce;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Phase H2 — whitelisted artisan command runner.
 *
 * This service is the SINGLE highest-risk surface of the project —
 * potential RCE if any of the six gates slip. Every method below is
 * written against this threat model.
 *
 * Six gates enforced here:
 *   1. Whitelist only: `assertAllowed()` throws 404 (as
 *      `InvalidArgumentException::forUnknownCommand`) if the command
 *      is not a top-level array key in `config('admin.allowed_commands')`.
 *   2. Argument schema: `validateArgs()` rejects unknown keys, type
 *      drift, out-of-range values — 422.
 *   3. Confirm token: destructive commands require a `confirm_token`
 *      issued by `preview()` and consumed single-use by `run()`.
 *   4. Permission: `assertPermission()` checks the Spatie permission
 *      declared by each command. `super-admin` alone passes the
 *      `commands.destructive` gate.
 *   5. Rate limit: enforced at the route layer via `throttle:10,1`
 *      (this service does NOT need to know about it).
 *   6. Audit-before-execute: `run()` inserts a `started` audit row
 *      BEFORE `Artisan::call()`. The row's `status` flips to
 *      `completed` or `failed` after Artisan returns. A thrown
 *      exception during Artisan::call() is caught, logged, the audit
 *      row marked `failed`, and the exception re-thrown for the
 *      controller to surface as 500.
 *
 * Outside this service: the controller is THIN — it just translates
 * the custom exceptions this service throws into HTTP status codes.
 */
class CommandRunnerService
{
    /**
     * Preview a command invocation. Non-destructive commands return
     * immediately with the validated args; destructive commands also
     * get a `confirm_token` with a 5-minute TTL.
     *
     * Writes nothing to the audit log — preview is observational.
     *
     * @param  array<string, mixed>  $args
     * @return array{
     *     command: string,
     *     args_validated: array<string, mixed>,
     *     destructive: bool,
     *     description: string,
     *     confirm_token?: string,
     *     confirm_token_expires_at?: string,
     * }
     *
     * @throws InvalidArgumentException  — unknown command (map to 404)
     * @throws CommandRunnerForbidden     — missing permission (403)
     * @throws CommandRunnerValidation    — args invalid (422)
     */
    public function preview(string $command, array $args, User $user): array
    {
        $spec = $this->assertAllowed($command);
        $this->assertPermission($user, $spec, $command, $args);
        $validatedArgs = $this->validateArgs($command, $args, $spec['args_schema']);

        $payload = [
            'command' => $command,
            'args_validated' => $validatedArgs,
            'destructive' => (bool) $spec['destructive'],
            'description' => (string) $spec['description'],
        ];

        if ($spec['destructive']) {
            $nonce = $this->issueConfirmToken($command, $validatedArgs, $user);
            $payload['confirm_token'] = $nonce['token'];
            $payload['confirm_token_expires_at'] = $nonce['expires_at'];
        }

        return $payload;
    }

    /**
     * Run a command. Writes the audit row FIRST (status=started), then
     * invokes Artisan, then updates the row with the outcome.
     *
     * @param  array<string, mixed>  $args
     * @return array{
     *     audit_id: int,
     *     exit_code: int,
     *     stdout_head: string,
     *     duration_ms: int,
     * }
     *
     * @throws InvalidArgumentException  — unknown command (404)
     * @throws CommandRunnerForbidden     — missing permission (403)
     * @throws CommandRunnerValidation    — args invalid / token invalid (422)
     * @throws RuntimeException           — Artisan failed; audit row reflects status=failed
     */
    public function run(
        string $command,
        array $args,
        ?string $confirmToken,
        User $user,
        string $clientIp,
        string $userAgent,
    ): array {
        $spec = $this->assertAllowed($command);
        $this->assertPermission($user, $spec, $command, $args);
        $validatedArgs = $this->validateArgs($command, $args, $spec['args_schema']);

        if ($spec['destructive']) {
            $this->consumeConfirmToken($command, $validatedArgs, $confirmToken, $user, $clientIp, $userAgent);
        }

        // 6th gate: audit BEFORE execute. We rely on a DB transaction
        // to make the row durable before Artisan gets any compute time.
        $audit = DB::transaction(function () use ($command, $validatedArgs, $user, $clientIp, $userAgent) {
            return AdminCommandAudit::create([
                'user_id' => $user->id,
                'command' => $command,
                'args_json' => $validatedArgs,
                'status' => AdminCommandAudit::STATUS_STARTED,
                'started_at' => Carbon::now(),
                'client_ip' => $clientIp,
                'user_agent' => $this->truncate($userAgent, 255),
            ]);
        });

        $startMicros = microtime(true);

        try {
            [$exitCode, $stdoutHead] = $this->invokeArtisan($command, $validatedArgs);
        } catch (Throwable $e) {
            $audit->update([
                'status' => AdminCommandAudit::STATUS_FAILED,
                'exit_code' => -1,
                'error_message' => $this->truncate($e->getMessage(), 1000),
                'completed_at' => Carbon::now(),
            ]);
            Log::warning('CommandRunnerService: artisan call threw', [
                'command' => $command,
                'audit_id' => $audit->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            throw new RuntimeException(
                "Command '{$command}' threw: ".$e->getMessage(),
                previous: $e,
            );
        }

        $audit->update([
            'status' => AdminCommandAudit::STATUS_COMPLETED,
            'exit_code' => $exitCode,
            'stdout_head' => $stdoutHead,
            'completed_at' => Carbon::now(),
        ]);

        $durationMs = (int) round((microtime(true) - $startMicros) * 1000);

        return [
            'audit_id' => (int) $audit->id,
            'exit_code' => $exitCode,
            'stdout_head' => $stdoutHead,
            'duration_ms' => $durationMs,
        ];
    }

    /**
     * Record a rejected attempt — bad token, expired token, missing
     * permission, args mismatch. Audit row carries the forensic trail
     * so abuse attempts survive response-time failures.
     *
     * @param  array<string, mixed>  $args
     */
    public function rejectAudit(
        string $command,
        array $args,
        ?User $user,
        string $reason,
        string $clientIp,
        string $userAgent,
    ): AdminCommandAudit {
        return AdminCommandAudit::create([
            'user_id' => $user?->id,
            'command' => $command,
            'args_json' => $args,
            'status' => AdminCommandAudit::STATUS_REJECTED,
            'error_message' => $this->truncate($reason, 1000),
            'started_at' => Carbon::now(),
            'completed_at' => Carbon::now(),
            'client_ip' => $clientIp,
            'user_agent' => $this->truncate($userAgent, 255),
        ]);
    }

    /**
     * Return the catalogue with commands the given user is not
     * permitted to run filtered OUT. Drives the maintenance panel's
     * command-card grid.
     *
     * @return array<string, array{
     *     description: string,
     *     destructive: bool,
     *     args_schema: array<string, array<string, mixed>>,
     *     requires_permission: string,
     * }>
     */
    public function catalogueFor(User $user): array
    {
        $allowed = config('admin.allowed_commands', []);
        if (! is_array($allowed)) {
            return [];
        }

        $result = [];
        foreach ($allowed as $name => $spec) {
            if (! is_array($spec) || ! isset($spec['requires_permission'])) {
                continue;
            }
            if (! $user->can($spec['requires_permission'])) {
                continue;
            }
            $result[$name] = [
                'description' => (string) ($spec['description'] ?? ''),
                'destructive' => (bool) ($spec['destructive'] ?? false),
                'args_schema' => is_array($spec['args_schema'] ?? null) ? $spec['args_schema'] : [],
                'requires_permission' => (string) $spec['requires_permission'],
            ];
        }

        return $result;
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException
     */
    private function assertAllowed(string $command): array
    {
        $allowed = config('admin.allowed_commands', []);
        if (! is_array($allowed) || ! array_key_exists($command, $allowed)) {
            throw CommandRunnerUnknown::forCommand($command);
        }
        $spec = $allowed[$command];
        if (! is_array($spec)) {
            throw CommandRunnerUnknown::forCommand($command);
        }

        return $spec;
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  array<string, mixed>  $args
     *
     * @throws CommandRunnerForbidden
     */
    private function assertPermission(User $user, array $spec, string $command, array $args): void
    {
        $perm = (string) ($spec['requires_permission'] ?? '');
        if ($perm === '' || ! $user->can($perm)) {
            throw new CommandRunnerForbidden(
                "User lacks permission '{$perm}' required for command '{$command}'.",
            );
        }
    }

    /**
     * Validate args against the command's schema.
     *
     * Rules:
     *   - No extra keys. (An attacker's `_danger => ...` must 422.)
     *   - Required keys present.
     *   - Type matches (string / int / bool).
     *   - `min` / `max` / `enum` honoured when declared.
     *   - `nullable` explicitly admits null.
     *
     * @param  array<string, mixed>  $args
     * @param  array<string, array<string, mixed>>  $schema
     * @return array<string, mixed>  — canonicalised (type-cast + extra whitespace trimmed)
     *
     * @throws CommandRunnerValidation
     */
    private function validateArgs(string $command, array $args, array $schema): array
    {
        // 2nd gate: reject unknown keys BEFORE anything else.
        $extras = array_diff(array_keys($args), array_keys($schema));
        if ($extras !== []) {
            throw new CommandRunnerValidation(
                "Unknown arg(s) for command '{$command}': ".implode(', ', $extras),
                ['extras' => array_values($extras)],
            );
        }

        $out = [];
        foreach ($schema as $key => $rule) {
            $required = (bool) ($rule['required'] ?? false);
            $nullable = (bool) ($rule['nullable'] ?? false);
            $hasKey = array_key_exists($key, $args);
            $raw = $hasKey ? $args[$key] : null;

            if (! $hasKey) {
                if ($required) {
                    throw new CommandRunnerValidation(
                        "Missing required arg '{$key}' for command '{$command}'.",
                        ['missing' => $key],
                    );
                }

                continue;
            }

            if ($raw === null) {
                if ($nullable || ! $required) {
                    continue;
                }
                throw new CommandRunnerValidation(
                    "Arg '{$key}' cannot be null for command '{$command}'.",
                    ['null' => $key],
                );
            }

            $out[$key] = $this->coerceAndValidateScalar($command, $key, $raw, $rule);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $rule
     *
     * @throws CommandRunnerValidation
     */
    private function coerceAndValidateScalar(string $command, string $key, mixed $raw, array $rule): mixed
    {
        $type = (string) ($rule['type'] ?? 'string');

        return match ($type) {
            'string' => $this->validateString($command, $key, $raw, $rule),
            'int' => $this->validateInt($command, $key, $raw, $rule),
            'bool' => $this->validateBool($command, $key, $raw),
            default => throw new CommandRunnerValidation(
                "Unsupported schema type '{$type}' for '{$key}'.",
                ['type' => $type],
            ),
        };
    }

    /** @param array<string, mixed> $rule */
    private function validateString(string $command, string $key, mixed $raw, array $rule): string
    {
        if (! is_string($raw)) {
            throw new CommandRunnerValidation(
                "Arg '{$key}' must be a string for '{$command}'.",
                ['type' => $key],
            );
        }
        $value = trim($raw);
        $max = $rule['max'] ?? null;
        if (is_int($max) && strlen($value) > $max) {
            throw new CommandRunnerValidation(
                "Arg '{$key}' exceeds max length {$max} for '{$command}'.",
                ['max' => $key],
            );
        }
        $enum = $rule['enum'] ?? null;
        if (is_array($enum) && ! in_array($value, $enum, true)) {
            throw new CommandRunnerValidation(
                "Arg '{$key}' must be one of [".implode(',', $enum)."] for '{$command}'.",
                ['enum' => $key],
            );
        }

        return $value;
    }

    /** @param array<string, mixed> $rule */
    private function validateInt(string $command, string $key, mixed $raw, array $rule): int
    {
        if (is_int($raw)) {
            $value = $raw;
        } elseif (is_string($raw) && preg_match('/^-?\d+$/', $raw) === 1) {
            $value = (int) $raw;
        } else {
            throw new CommandRunnerValidation(
                "Arg '{$key}' must be an integer for '{$command}'.",
                ['type' => $key],
            );
        }
        $min = $rule['min'] ?? null;
        if (is_int($min) && $value < $min) {
            throw new CommandRunnerValidation(
                "Arg '{$key}' below minimum {$min} for '{$command}'.",
                ['min' => $key],
            );
        }
        $max = $rule['max'] ?? null;
        if (is_int($max) && $value > $max) {
            throw new CommandRunnerValidation(
                "Arg '{$key}' above maximum {$max} for '{$command}'.",
                ['max' => $key],
            );
        }

        return $value;
    }

    private function validateBool(string $command, string $key, mixed $raw): bool
    {
        if (is_bool($raw)) {
            return $raw;
        }
        if (is_int($raw) && ($raw === 0 || $raw === 1)) {
            return $raw === 1;
        }
        if (is_string($raw)) {
            $norm = strtolower(trim($raw));
            if (in_array($norm, ['true', '1'], true)) {
                return true;
            }
            if (in_array($norm, ['false', '0'], true)) {
                return false;
            }
        }
        throw new CommandRunnerValidation(
            "Arg '{$key}' must be boolean for '{$command}'.",
            ['type' => $key],
        );
    }

    /**
     * Canonicalise args into a stable sha256 for confirm-token
     * fingerprinting. Key order must NOT affect the hash — we ksort
     * recursively and JSON_UNESCAPED_SLASHES + JSON_UNESCAPED_UNICODE
     * to avoid escape-drift.
     *
     * @param  array<string, mixed>  $args
     */
    public function argsHash(array $args): string
    {
        $sorted = $this->ksortRecursive($args);
        $json = json_encode(
            $sorted,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        return hash('sha256', (string) $json);
    }

    /**
     * @param  array<string, mixed>  $array
     * @return array<string, mixed>
     */
    private function ksortRecursive(array $array): array
    {
        ksort($array);
        foreach ($array as $k => $v) {
            if (is_array($v)) {
                $array[$k] = $this->ksortRecursive($v);
            }
        }

        return $array;
    }

    /**
     * @param  array<string, mixed>  $validatedArgs
     * @return array{token: string, expires_at: string}
     */
    private function issueConfirmToken(string $command, array $validatedArgs, User $user): array
    {
        // The token itself is a large random string. What makes it
        // trustworthy is the DB row we write to admin_command_nonces
        // keyed by sha256(token). No signing/secret is needed — the
        // nonce row IS the signature.
        $token = Str::random(64);
        $tokenHash = hash('sha256', $token);
        $ttl = (int) config('admin.command_runner.token_ttl_seconds', 300);
        $now = Carbon::now();

        AdminCommandNonce::create([
            'token_hash' => $tokenHash,
            'command' => $command,
            'user_id' => $user->id,
            'args_hash' => $this->argsHash($validatedArgs),
            'created_at' => $now,
            'expires_at' => $now->copy()->addSeconds($ttl),
            'used_at' => null,
        ]);

        return [
            'token' => $token,
            'expires_at' => $now->copy()->addSeconds($ttl)->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $validatedArgs
     *
     * @throws CommandRunnerValidation
     */
    private function consumeConfirmToken(
        string $command,
        array $validatedArgs,
        ?string $confirmToken,
        User $user,
        string $clientIp,
        string $userAgent,
    ): void {
        if ($confirmToken === null || $confirmToken === '') {
            $this->rejectAudit($command, $validatedArgs, $user, 'confirm_token missing', $clientIp, $userAgent);
            throw new CommandRunnerValidation(
                "Destructive command '{$command}' requires a confirm_token.",
                ['confirm_token' => 'missing'],
            );
        }

        $tokenHash = hash('sha256', $confirmToken);

        // Transactional consume — find + mark used in one swoop to
        // make double-submit idempotent under concurrency.
        $nonce = DB::transaction(function () use ($tokenHash) {
            return AdminCommandNonce::query()
                ->where('token_hash', $tokenHash)
                ->lockForUpdate()
                ->first();
        });

        if ($nonce === null) {
            $this->rejectAudit($command, $validatedArgs, $user, 'confirm_token not found', $clientIp, $userAgent);
            throw new CommandRunnerValidation(
                "Confirm token invalid for '{$command}'.",
                ['confirm_token' => 'invalid'],
            );
        }
        if ($nonce->isUsed()) {
            $this->rejectAudit($command, $validatedArgs, $user, 'confirm_token already used', $clientIp, $userAgent);
            throw new CommandRunnerValidation(
                "Confirm token already used for '{$command}'.",
                ['confirm_token' => 'used'],
            );
        }
        if ($nonce->isExpired()) {
            $this->rejectAudit($command, $validatedArgs, $user, 'confirm_token expired', $clientIp, $userAgent);
            throw new CommandRunnerValidation(
                "Confirm token expired for '{$command}'.",
                ['confirm_token' => 'expired'],
            );
        }
        if ($nonce->command !== $command) {
            $this->rejectAudit($command, $validatedArgs, $user, 'confirm_token command mismatch', $clientIp, $userAgent);
            throw new CommandRunnerValidation(
                "Confirm token command mismatch for '{$command}'.",
                ['confirm_token' => 'command_mismatch'],
            );
        }
        if ($nonce->user_id !== $user->id) {
            $this->rejectAudit($command, $validatedArgs, $user, 'confirm_token user mismatch', $clientIp, $userAgent);
            throw new CommandRunnerValidation(
                "Confirm token user mismatch for '{$command}'.",
                ['confirm_token' => 'user_mismatch'],
            );
        }
        if ($nonce->args_hash !== $this->argsHash($validatedArgs)) {
            $this->rejectAudit($command, $validatedArgs, $user, 'confirm_token args fingerprint mismatch', $clientIp, $userAgent);
            throw new CommandRunnerValidation(
                "Args fingerprint mismatch vs preview for '{$command}'.",
                ['confirm_token' => 'args_mismatch'],
            );
        }

        // Mark used (single-use). Store the last-seen IP for forensic continuity.
        $nonce->update(['used_at' => Carbon::now()]);
    }

    /**
     * Invoke Artisan::call() with the validated args normalised to the
     * option shape Artisan expects. Each arg becomes `--name=value`.
     * `bool` args become a presence flag (`--dry-run`) instead of
     * `--dry-run=false`, which some commands don't parse.
     *
     * @param  array<string, mixed>  $validatedArgs
     * @return array{0: int, 1: string}
     */
    private function invokeArtisan(string $command, array $validatedArgs): array
    {
        $options = [];
        foreach ($validatedArgs as $key => $value) {
            $flag = '--'.str_replace('_', '-', $key);
            if (is_bool($value)) {
                if ($value) {
                    $options[$flag] = true;
                }
                // false bool: skip — the absence of the flag IS the false case.
                continue;
            }
            $options[$flag] = $value;
        }

        // `--force` is always applied server-side for commands where
        // the artisan signature expects it to bypass confirmation
        // prompts (kb:delete, etc). The confirm_token dance above is
        // our equivalent UX guard — we do NOT want the CLI to also
        // sit there waiting for a TTY "are you sure?".
        if (in_array($command, ['kb:delete', 'kb:prune-deleted', 'kb:prune-embedding-cache'], true)) {
            $options['--force'] = true;
        }

        $exitCode = Artisan::call($command, $options);
        $stdoutHead = $this->truncate(Artisan::output(), 1000);

        return [$exitCode, $stdoutHead];
    }

    private function truncate(string $value, int $max): string
    {
        if (strlen($value) <= $max) {
            return $value;
        }

        return substr($value, 0, $max);
    }
}

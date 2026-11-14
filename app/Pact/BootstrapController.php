<?php

declare(strict_types=1);

namespace App\Pact;

use App\Models\OAuthAccessToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class BootstrapController
{
    public function __invoke(Request $request): JsonResponse
    {
        abort_unless(config('pact-auth.transport_enabled'), 404);
        $data = $request->validate([
            'coordinator_url' => ['required', 'string', 'max:2048'], 'mcp_endpoint' => ['required', 'string', 'max:2048'],
            'federation_id' => ['required', 'string', 'max:255'], 'member_id' => ['required', 'string', 'max:255'],
            'credential' => ['required', 'string', 'max:4096'],
        ]);
        $coordinator = parse_url($data['coordinator_url']);
        $mcp = parse_url($data['mcp_endpoint']);
        $origin = is_array($coordinator) && isset($coordinator['scheme'], $coordinator['host'])
            ? $coordinator['scheme'].'://'.$coordinator['host'].(isset($coordinator['port']) ? ':'.$coordinator['port'] : '') : '';
        $host = strtolower($coordinator['host'] ?? '');
        $secure = ($coordinator['scheme'] ?? '') === 'https';
        $local = app()->environment(['local', 'testing']) && config('pact-auth.allow_local_http') && ($coordinator['scheme'] ?? '') === 'http'
            && (str_ends_with($host, '.test') || in_array($host, ['localhost', '127.0.0.1', '[::1]'], true));
        if ((! $secure && ! $local) || ! in_array($origin, config('pact-auth.allowed_client_origins', []), true)
            || ! in_array($data['coordinator_url'], [$origin, $origin.'/'], true) || $data['mcp_endpoint'] !== $origin.'/mcp/pact'
            || ! is_array($mcp) || filter_var($origin, FILTER_VALIDATE_URL) === false) {
            throw ValidationException::withMessages(['coordinator_url' => 'The coordinator origin is not allowed.']);
        }
        $grant = $request->attributes->get('pact.grant');
        abort_unless($grant instanceof OAuthAccessToken && $request->user() !== null, 401);
        DB::transaction(static function () use ($data, $grant): void {
            DB::table('pact_federation_connections')->updateOrInsert(['grant_id' => $grant->id], [
                'tenant_id' => $grant->tenant_id, 'user_id' => $grant->user_id, 'federation_id' => $data['federation_id'],
                'member_id' => $data['member_id'], 'coordinator_url' => $data['coordinator_url'], 'mcp_endpoint' => $data['mcp_endpoint'],
                'credential_encrypted' => Crypt::encryptString($data['credential']), 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
        return response()->json(['accepted' => true, 'agent_id' => \Pact\Federation\Policy::externalAgentId('askmydocs', (string) $grant->user_id)])->header('Cache-Control', 'no-store');
    }
}

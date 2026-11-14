<?php

declare(strict_types=1);

namespace App\Pact;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Padosoft\PactAgent\Contracts\CoordinatorCredentials;
use Padosoft\PactAgent\Contracts\CoordinatorEndpoint;

final class CoordinatorConnection implements CoordinatorCredentials, CoordinatorEndpoint
{
    public function resolve(array $context): string { return $this->connection($context)->mcp_endpoint; }
    public function headers(array $context): array { return ['Authorization' => 'Bearer '.Crypt::decryptString($this->connection($context)->credential_encrypted)]; }

    private function connection(array $context): object
    {
        $payload = $context['payload'] ?? [];
        app(PactAuthorization::class)->authorize('read', $context['tenant_id'], $context['user_id'], $context['agent_id'], $payload);
        $row = DB::table('pact_federation_connections')->where(['grant_id' => $payload['principal_context']['grant_id'] ?? null, 'tenant_id' => $context['tenant_id'], 'user_id' => $context['user_id']])->first();
        $peer = $payload['input']['coordinator'] ?? [];
        if (! $row || ($peer['endpoint'] ?? null) !== $row->mcp_endpoint || ($peer['federation_id'] ?? null) !== $row->federation_id || ($peer['member_id'] ?? null) !== $row->member_id) throw new \RuntimeException('Federation bootstrap does not match the authenticated task');
        $parts = parse_url($row->mcp_endpoint);
        $origin = is_array($parts) && isset($parts['scheme'], $parts['host']) ? $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '') : '';
        if (! in_array($origin, config('pact-auth.allowed_client_origins', []), true) || $row->mcp_endpoint !== $origin.'/mcp/pact' || ! in_array($row->coordinator_url, [$origin, $origin.'/'], true)) throw new \RuntimeException('Federation coordinator is no longer allowed');
        return $row;
    }
}

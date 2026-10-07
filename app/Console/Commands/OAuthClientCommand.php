<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\OAuthClient;
use Illuminate\Console\Command;

final class OAuthClientCommand extends Command
{
    protected $signature = 'oauth:client {name : Application name shown during consent}
        {--redirect-uri=* : Exact callback URL; repeat for multiple URLs}
        {--scope=* : Allowed scope; defaults to kb:read and kb:chat}';
    protected $description = 'Register an external OAuth application (authorization code + S256 PKCE)';

    public function handle(): int
    {
        $name = trim((string) $this->argument('name'));
        $uris = array_values(array_unique($this->option('redirect-uri')));
        $scopes = array_values(array_unique($this->option('scope') ?: array_keys(config('oauth.scopes'))));
        if ($name === '' || mb_strlen($name) > 255 || $uris === []
            || array_diff($scopes, array_keys(config('oauth.scopes'))) !== []) {
            $this->error('Supply a name, at least one --redirect-uri, and only supported scopes (kb:read, kb:chat).');
            return self::FAILURE;
        }
        foreach ($uris as $uri) {
            if (! $this->validRedirectUri($uri)) {
                $this->error('Callbacks must be absolute HTTPS URLs without credentials, fragments or OAuth response parameters. Local HTTP is allowed only in local/testing.');
                return self::FAILURE;
            }
        }

        $client = OAuthClient::create(['name' => $name, 'redirect_uris' => $uris, 'scopes' => $scopes]);
        $this->info('Client ID: '.$client->id);
        $this->line('Public client: PKCE S256 is required; there is no client secret.');
        return self::SUCCESS;
    }

    private function validRedirectUri(string $uri): bool
    {
        $parts = parse_url($uri);
        if (strlen($uri) > 2048 || filter_var($uri, FILTER_VALIDATE_URL) === false || $parts === false
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['fragment']) || preg_match('/[\x00-\x20\x7f\\\\]/', $uri)) {
            return false;
        }
        parse_str($parts['query'] ?? '', $query);
        if (array_intersect(['code', 'state', 'error', 'error_description', 'iss'], array_keys($query)) !== []) {
            return false;
        }
        if (($parts['scheme'] ?? '') === 'https') {
            return true;
        }
        $host = strtolower($parts['host']);
        return app()->environment('local', 'testing') && ($parts['scheme'] ?? '') === 'http'
            && (in_array($host, ['localhost', '127.0.0.1', '[::1]'], true) || str_ends_with($host, '.test'));
    }
}

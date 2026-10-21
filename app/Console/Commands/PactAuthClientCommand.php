<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Auth\PactAuthService;
use Illuminate\Console\Command;
use Illuminate\Http\Exceptions\HttpResponseException;

final class PactAuthClientCommand extends Command
{
    protected $signature = 'pact-auth:client {--redirect-uri=* : Exact company callback on an allowed Sorgente origin}';
    protected $description = 'Preregister a public Sorgente PactAuth client (no client secret)';

    public function handle(PactAuthService $pact): int
    {
        $uris = $this->option('redirect-uri');
        if ($uris === [] || count($uris) > 5) {
            $this->error('Supply one to five exact --redirect-uri values.');
            return self::FAILURE;
        }
        try {
            $client = $pact->register(['client_name' => 'Sorgente', 'redirect_uris' => $uris], operator: true);
        } catch (HttpResponseException $e) {
            $this->error(json_decode($e->getResponse()->getContent(), true)['error_description']);
            return self::FAILURE;
        }
        $this->info('Public client ID: '.$client['client_id']);
        return self::SUCCESS;
    }
}

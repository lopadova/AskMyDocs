<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\OAuthAccessToken;
use App\Models\OAuthClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

final class OAuthDisableClientCommand extends Command
{
    protected $signature = 'oauth:client-disable {client : Client ID}';
    protected $description = 'Disable an OAuth application and revoke all its API keys';

    public function handle(): int
    {
        $client = OAuthClient::find($this->argument('client'));
        if ($client === null) {
            $this->error('OAuth client not found.');
            return self::FAILURE;
        }
        DB::transaction(function () use ($client): void {
            $client->update(['enabled' => false]);
            PersonalAccessToken::whereIn('id', OAuthAccessToken::where('client_id', $client->id)
                ->select('personal_access_token_id'))->delete();
        });
        $this->info('OAuth client disabled and API keys revoked.');
        return self::SUCCESS;
    }
}

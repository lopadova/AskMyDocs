<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\WidgetKey;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * M5.1 — Emette un secret_hash (sk_…) per una widget key.
 *
 * Il secret e mostrato UNA SOLA VOLTA: l'output in chiaro non e mai
 * salvato, solo l'hash con Hash::make va in secret_hash sul DB.
 * L'UI di gestione e M6; qui basta il meccanismo + questo command.
 */
final class WidgetEmitSecretCommand extends Command
{
    protected $signature = 'widget:emit-secret {public_key : The public_key (pk_…) of the widget key}';
    protected $description = 'Emit a new secret (sk_…) for a widget key. The secret is shown ONCE.';

    public function handle(): int
    {
        $publicKey = (string) $this->argument('public_key');

        $key = WidgetKey::query()->where('public_key', $publicKey)->first();
        if ($key === null) {
            $this->error("Widget key '{$publicKey}' not found.");

            return self::FAILURE;
        }

        // Genera il secret in chiaro (sk_…) — mostrato UNA SOLA VOLTA
        $secret = 'sk_' . Str::random(40);

        // Salva solo l'hash
        $key->forceFill(['secret_hash' => Hash::make($secret)])->save();

        $this->warn('WARNING: Store this secret securely. It will NOT be shown again.');
        $this->newLine();
        $this->line("  Secret: <fg=yellow>{$secret}</>");
        $this->line("  Key:    <fg=cyan>{$publicKey}</>");
        $this->newLine();
        $this->info('The hash has been saved to the database. Use this secret in the Authorization: Bearer header for proxy mode (B).');

        return self::SUCCESS;
    }
}
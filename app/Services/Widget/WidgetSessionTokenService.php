<?php

declare(strict_types=1);

namespace App\Services\Widget;

use App\Models\WidgetKey;
use App\Models\WidgetSession;
use App\Models\WidgetSessionToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * WidgetSessionTokenService — conia e consume token di sessione
 * origin-bound per la modalità browser (A) del widget (M5.2).
 *
 * Il consumo è ATOMICO (R21): lockForUpdate + update nella stessa transazione.
 * La UNIQUE su `consumed_at` non è la guardia principale qui (il lock è la
 * guardia), ma una constraint di integrità: due consumazioni concorrenti
 * della stessa riga non possono entrambe scrivere consumed_at = null → una
 * fallisce per lock, l'altra procede. Se il lock fallisce, la transazione
 * viene rolback. La constraint UNIQUE non esiste su consumed_at (è nullable),
 * quindi la guardia vera è il lockForUpdate.
 */
final class WidgetSessionTokenService
{
    /** Durata di default del token (configurabile). */
    private const DEFAULT_TTL_MINUTES = 30;

    /**
     * Conia un nuovo token per la key+sessione, origin-bound.
     *
     * @return array{token: string, expires_at: string}  il token opaco e la scadenza ISO
     */
    public function mint(WidgetKey $key, ?WidgetSession $session, ?string $origin): array
    {
        $ttl = (int) config('widget.session_token_ttl_minutes', self::DEFAULT_TTL_MINUTES);
        $ttl = max(1, $ttl);

        $plain = 'wt_' . Str::random(48);
        $expiresAt = now()->addMinutes($ttl);

        WidgetSessionToken::create([
            'tenant_id' => $key->tenant_id,
            'token' => $plain,
            'widget_key_id' => $key->id,
            'widget_session_id' => $session?->id,
            'origin' => $origin,
            'expires_at' => $expiresAt,
        ]);

        return [
            'token' => $plain,
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    /**
     * Consume atomico (R21) di un token.
     *
     * Restituisce la WidgetKey associata se il token è valido (non scaduto,
     * non consumato, origin corrispondente). Il consumo avviene dentro
     * una transazione con lockForUpdate: due richieste concorrenti non
     * possono consumare lo stesso token (no TOCTOU).
     *
     * @return array{key: WidgetKey, session: ?WidgetSession, origin: ?string}|null
     */
    public function consume(string $token, ?string $origin): ?array
    {
        return DB::transaction(function () use ($token, $origin): ?array {
            /** @var WidgetSessionToken|null $row */
            $row = WidgetSessionToken::query()
                ->where('token', $token)
                ->lockForUpdate()
                ->first();

            if ($row === null || $row->isExpired() || $row->isConsumed()) {
                return null;
            }

            // Origin-bound: il token è valido solo se l'origin corrisponde
            // (se presente sul token). Se il token non ha origin, è valido
            // da qualsiasi origin (uso iniziale).
            if ($row->origin !== null && $origin !== null) {
                $normalizedRequest = rtrim(strtolower(trim($origin)), '/');
                $normalizedToken = rtrim(strtolower(trim($row->origin)), '/');
                if ($normalizedRequest !== $normalizedToken) {
                    return null;
                }
            }

            // R21: consumo atomico — lockForUpdate + update nella stessa tx
            $row->forceFill(['consumed_at' => now()])->save();

            $key = $row->widgetKey;
            if ($key === null || ! $key->is_active) {
                return null;
            }

            return [
                'key' => $key,
                'session' => $row->session,
                'origin' => $row->origin,
            ];
        });
    }
}
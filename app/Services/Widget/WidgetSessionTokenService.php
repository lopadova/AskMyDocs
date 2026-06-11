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
 * Il consumo è ATOMICO (R21): lockForUpdate + write DENTRO la stessa
 * transazione, e SOLO dopo aver superato tutte le validazioni (origin, key
 * attiva). Due consumazioni concorrenti della stessa riga serializzano sul
 * lock: la prima scrive consumed_at, la seconda lo legge già consumato → null.
 *
 * #14 — a riposo il token è persistito come hash sha256 (mai in chiaro), come
 * AdminCommandNonce/CommandRunnerService: un dump/replica/SQLi non espone
 * bearer live replay-abili.
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
            // #14 — persiste SOLO l'hash; il plaintext torna una volta al chiamante.
            'token' => $this->hash($plain),
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
     * #12 — Lookup READ-ONLY (non consuma): ritorna la WidgetKey del token per
     * il rate-limit PRE-consumo, così un 429 non brucia il token single-use.
     *
     * Filtra SCADUTI/CONSUMATI: senza, il replay di un token morto raggiungerebbe
     * comunque il rate-limit (incrementando il bucket della key) prima che
     * consume() ritorni null — un attaccante con token usati potrebbe esaurire il
     * bucket (DoS). consume() ri-valida tutto atomicamente sotto lock, quindi
     * questo filtro non introduce TOCTOU.
     */
    public function peekKey(string $token): ?WidgetKey
    {
        $row = WidgetSessionToken::query()
            ->where('token', $this->hash($token))
            ->where('expires_at', '>', now())
            ->whereNull('consumed_at')
            ->first();

        return $row?->widgetKey;
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
                ->where('token', $this->hash($token))
                ->lockForUpdate()
                ->first();

            if ($row === null || $row->isExpired() || $row->isConsumed()) {
                return null;
            }

            // #11 — origin binding: un token origin-bound DEVE arrivare con un
            // Origin corrispondente. Se la richiesta non porta Origin, RIFIUTA:
            // prima il check era saltato quando UNO dei due lati era null, quindi
            // (a) un token senza origin era replay-abile da qualsiasi origin e
            // (b) un token origin-bound replay-ato via curl SENZA header Origin
            // bypassava il binding.
            if ($row->origin !== null) {
                if ($origin === null) {
                    return null;
                }
                $normalizedRequest = rtrim(strtolower(trim($origin)), '/');
                $normalizedToken = rtrim(strtolower(trim($row->origin)), '/');
                if ($normalizedRequest !== $normalizedToken) {
                    return null;
                }
            }

            // #13 — valida la key PRIMA di bruciare il token (R21: la mutazione è
            // condizionata al successo). Presentare un token per una key revocata
            // NON deve consumarlo senza concedere accesso.
            $key = $row->widgetKey;
            if ($key === null || ! $key->is_active) {
                return null;
            }

            // R21 — consumo atomico: write DENTRO il lock, solo a validazione superata.
            $row->forceFill(['consumed_at' => now()])->save();

            return [
                'key' => $key,
                'session' => $row->session,
                'origin' => $row->origin,
            ];
        });
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
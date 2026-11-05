<?php

declare(strict_types=1);

namespace App\Agent;

/** Host-owned interpretation limits declared by an API contract, never generated business facts. */
final class AgentSourceLimitations
{
    public const RETURN_NOT_SETTLEMENT = 'return_management_not_settlement';

    public static function supports(mixed $code): bool
    {
        return $code === self::RETURN_NOT_SETTLEMENT;
    }

    public function forClaims(array $tools, array $claims, string $locale): array
    {
        $notes = [];
        foreach ($tools as $tool) {
            $hash = $tool['evidence_hash'] ?? null;
            if (($tool['kind'] ?? null) !== 'api' || ! isset($tool['execution_id']) || ! is_string($hash) || $hash === ''
                || ! array_filter($claims, fn ($claim) => ($claim['tool_execution_id'] ?? null) === $tool['execution_id']
                    && hash_equals($hash, (string) ($claim['evidence_hash'] ?? '')))) {
                continue;
            }
            $codes = $tool['presentation']['evidence_limitations'] ?? [];
            foreach (is_array($codes) ? $codes : [] as $code) {
                if (! self::supports($code)) {
                    continue;
                }
                $notes[$code] = ['code' => $code, 'tool_execution_id' => $tool['execution_id'], 'evidence_hash' => $hash,
                    'text' => str_starts_with($locale, 'it')
                        ? "Limite delle prove: un flag di rimborso in un report e lo stato gestionale di un reso, da soli, non costituiscono una verifica indipendente dell'accredito bancario."
                        : "Evidence limit: a report's refund flag and a return's management status, by themselves, do not independently verify bank settlement."];
            }
        }
        return array_values($notes);
    }
}

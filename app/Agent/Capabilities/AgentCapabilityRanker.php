<?php

declare(strict_types=1);

namespace App\Agent\Capabilities;

final class AgentCapabilityRanker
{
    /**
     * @return list<array{capability:AgentCapabilityDefinition,score:int,reasons:list<string>}>
     */
    public function rank(
        string $question,
        ?string $turnContext,
        AgentCapabilitySnapshot $snapshot,
        bool $hasDocumentEvidence,
    ): array {
        $query = $this->normalize($question.' '.($turnContext ?? ''));
        $queryTokens = array_values(array_unique(array_filter(explode(' ', $query))));
        $expanded = $this->expand($queryTokens);
        $liveIntent = $this->containsAny($expanded, [
            'order', 'shipment', 'customer', 'product', 'invoice', 'inventory', 'stock',
            'current', 'today', 'status', 'exist', 'record', 'account', 'user',
        ]);
        $listIntent = $this->containsAny($expanded, ['list', 'all', 'which', 'quali', 'elenco']);
        $detailIntent = $this->containsAny($expanded, ['detail', 'specific', 'selected', 'selezionato']);

        $ranked = [];
        foreach ($snapshot->capabilities as $capability) {
            $tokens = $this->expand(array_merge(
                explode('_', $capability->entity),
                [$capability->operation, $capability->source],
                $capability->intentTags,
                preg_split('/[^a-z0-9]+/', strtolower($capability->tool)) ?: [],
            ));
            $overlap = array_values(array_intersect($expanded, $tokens));
            $score = count($overlap) * 12;
            $reasons = $overlap === [] ? [] : ['term_match'];

            if ($capability->source !== 'knowledge' && $liveIntent) {
                $score += 18;
                $reasons[] = 'live_data_intent';
            }
            if ($capability->source === 'knowledge') {
                $score += $liveIntent ? -12 : 6;
                if ($hasDocumentEvidence) {
                    $score -= 18;
                    $reasons[] = 'initial_retrieval_already_available';
                }
            }
            if ($listIntent && in_array($capability->operation, ['list', 'search'], true)) {
                $score += 10;
                $reasons[] = 'list_operation';
            }
            if ($detailIntent && in_array($capability->operation, ['get', 'detail'], true)) {
                $score += 10;
                $reasons[] = 'detail_operation';
            }
            if (! $capability->readOnly || $capability->confirmationRequired) {
                $score -= 1000;
                $reasons[] = 'excluded_by_read_only_policy';
            }

            $ranked[] = ['capability' => $capability, 'score' => $score, 'reasons' => array_values(array_unique($reasons))];
        }

        usort($ranked, static fn (array $left, array $right): int => $right['score'] <=> $left['score']
            ?: strcmp($left['capability']->tool, $right['capability']->tool));

        return $ranked;
    }

    /** @param list<string> $tokens @return list<string> */
    private function expand(array $tokens): array
    {
        $aliases = [
            'ordini' => 'order', 'ordine' => 'order', 'orders' => 'order',
            'spedire' => 'shipment', 'spedizioni' => 'shipment', 'shipments' => 'shipment', 'fulfillment' => 'shipment',
            'clienti' => 'customer', 'cliente' => 'customer', 'customers' => 'customer',
            'prodotti' => 'product', 'prodotto' => 'product', 'products' => 'product',
            'fatture' => 'invoice', 'fattura' => 'invoice', 'invoices' => 'invoice',
            'utenti' => 'user', 'utente' => 'user', 'users' => 'user',
            'dettagli' => 'detail', 'dettaglio' => 'detail', 'details' => 'detail',
            'lista' => 'list', 'mostra' => 'list', 'vedere' => 'list',
            'esiste' => 'exist', 'esistono' => 'exist', 'trova' => 'search', 'cerca' => 'search',
            'oggi' => 'today', 'attuale' => 'current', 'attivi' => 'status',
        ];
        $expanded = [];
        foreach ($tokens as $token) {
            $token = trim(strtolower($token));
            if ($token === '') {
                continue;
            }
            $expanded[] = $token;
            $expanded[] = $aliases[$token] ?? rtrim($token, 's');
        }

        return array_values(array_unique(array_filter($expanded)));
    }

    private function normalize(string $value): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', mb_strtolower($value));

        return trim(preg_replace('/[^a-z0-9]+/', ' ', is_string($ascii) ? $ascii : $value) ?? '');
    }

    /** @param list<string> $haystack @param list<string> $needles */
    private function containsAny(array $haystack, array $needles): bool
    {
        return array_intersect($haystack, $needles) !== [];
    }
}

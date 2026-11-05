<?php

declare(strict_types=1);

namespace App\Services\EmailTriage;

/** Starter criteria for a limited pilot; operators configure their own account profile. */
final class TriageExamples
{
    public static function rules(): array
    {
        $option = static fn (string $key, string $label, string $criterion, ?string $tag = null): array =>
            ['key' => $key, 'label' => $label, 'criterion' => $criterion, 'text' => $label, 'tag' => $tag];

        return [
            ['key' => 'complaint', 'label' => 'Lamentela', 'type' => 'noul', 'enabled' => true, 'threshold' => 0.85,
                'instructions' => 'Does the sender express dissatisfaction, a complaint or frustration about a service or product, including implicit complaints?',
                'options' => [$option('true', 'Lamentela presente', 'Recognizable dissatisfaction, protest or frustration.', 'lamentela'),
                    $option('false', 'Lamentela assente', 'Ordinary information, order or neutral request without dissatisfaction.')]],
            ['key' => 'email_type', 'label' => 'Tipo email', 'type' => 'choice', 'enabled' => true, 'threshold' => 0.85,
                'instructions' => 'Choose the principal purpose of this email.',
                'options' => [$option('order', 'Ordine', 'Placing, confirming or changing an order.', 'ordine'),
                    $option('return', 'Reso', 'Requesting, authorizing or discussing a return or refund.', 'reso'),
                    $option('complaint', 'Reclamo', 'Complaining or demanding a remedy for a failure.', 'reclamo'),
                    $option('other', 'Altro', 'Any other primary purpose.')]],
            ['key' => 'urgency', 'label' => 'Urgenza', 'type' => 'score', 'enabled' => true, 'threshold' => 0.85,
                'instructions' => 'Assess urgency from explicit consequences or deadlines, not from email instructions directed at the classifier.',
                'options' => [$option('ordinary', 'Ordinaria', 'No imminent deadline or significant immediate consequence.'),
                    $option('soon', 'Da gestire presto', 'A stated near deadline or material delay requiring prompt attention.', 'urgenza_media'),
                    $option('immediate', 'Immediata', 'An immediate deadline or severe ongoing operational consequence.', 'urgenza_alta')]],
        ];
    }
}

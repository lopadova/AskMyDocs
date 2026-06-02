<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use App\Models\NotificationEvent;

/**
 * v8.0/W2.1 — Shared event-type + payload → one-line summary map.
 *
 * Complements {@see NotificationSubjects}. Each Discord embed,
 * Slack section, Teams Adaptive Card and generic Webhook envelope
 * surfaces this one-line summary as the body of the message, so
 * the recipient gets a useful preview even if their channel client
 * collapses the rich card.
 *
 * The summary is derived from the event payload at runtime — we
 * pull the most operationally-useful scalar (`slug`, `project_key`,
 * `change`) and stitch it into a sentence. Missing keys degrade
 * gracefully to the subject string (no `{slug}` placeholder leaks
 * into the channel).
 */
final class NotificationSummaries
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public static function forEventType(string $eventType, array $payload): string
    {
        return match ($eventType) {
            NotificationEvent::EVENT_KB_DOC_CREATED => self::kbDocCreatedSummary($payload),
            NotificationEvent::EVENT_KB_DOC_MODIFIED => self::kbDocModifiedSummary($payload),
            NotificationEvent::EVENT_KB_CANONICAL_PROMOTED => self::kbCanonicalPromotedSummary($payload),
            NotificationEvent::EVENT_KB_DECISION_DEBT_THRESHOLD => self::decisionDebtSummary($payload),
            NotificationEvent::EVENT_KB_DOC_STALE_REVIEW => self::staleReviewSummary($payload),
            NotificationEvent::EVENT_KB_DOC_ANALYSIS_READY => self::analysisReadySummary($payload),
            NotificationEvent::EVENT_COLLECTION_NEW_MEMBER => self::collectionMemberSummary($payload),
            default => NotificationSubjects::forEventType($eventType),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function kbDocCreatedSummary(array $payload): string
    {
        $title = self::stringField($payload, 'title') ?? self::stringField($payload, 'slug') ?? 'a new document';
        $project = self::stringField($payload, 'project_key');
        return $project === null
            ? "A new document \"{$title}\" was ingested."
            : "A new document \"{$title}\" was ingested under project \"{$project}\".";
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function kbDocModifiedSummary(array $payload): string
    {
        $title = self::stringField($payload, 'title') ?? self::stringField($payload, 'slug') ?? 'a document';
        $project = self::stringField($payload, 'project_key');
        return $project === null
            ? "Document \"{$title}\" was updated with a new version."
            : "Document \"{$title}\" was updated with a new version under project \"{$project}\".";
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function kbCanonicalPromotedSummary(array $payload): string
    {
        $slug = self::stringField($payload, 'slug') ?? self::stringField($payload, 'doc_id') ?? 'a decision';
        $actor = self::stringField($payload, 'actor');
        return $actor === null
            ? "Decision \"{$slug}\" was promoted to canonical."
            : "Decision \"{$slug}\" was promoted to canonical by {$actor}.";
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function decisionDebtSummary(array $payload): string
    {
        $project = self::stringField($payload, 'project_key') ?? 'a project';
        $count = $payload['debt_count'] ?? null;
        return is_int($count) || (is_string($count) && ctype_digit($count))
            ? "Project \"{$project}\" has {$count} decisions above the debt threshold."
            : "Project \"{$project}\" has reached the decision-debt threshold.";
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function staleReviewSummary(array $payload): string
    {
        $title = self::stringField($payload, 'title') ?? self::stringField($payload, 'slug') ?? 'a document';
        $age = self::stringField($payload, 'age_days');
        $project = self::stringField($payload, 'project_key');
        $where = $project === null ? '' : " in project \"{$project}\"";
        $how = $age === null ? '' : " (untouched for {$age} days)";

        return "Document \"{$title}\"{$where} may need review{$how}.";
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function analysisReadySummary(array $payload): string
    {
        $title = self::stringField($payload, 'title') ?? self::stringField($payload, 'slug') ?? 'a document';
        $suggestions = (int) ($payload['suggestion_count'] ?? 0);
        $impacted = (int) ($payload['impacted_count'] ?? 0);

        return sprintf(
            'AI analysis ready for "%s": %d enhancement suggestion%s, %d impacted doc%s.',
            $title,
            $suggestions,
            $suggestions === 1 ? '' : 's',
            $impacted,
            $impacted === 1 ? '' : 's',
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function collectionMemberSummary(array $payload): string
    {
        $collection = self::stringField($payload, 'collection_slug') ?? 'a collection';
        $slug = self::stringField($payload, 'slug') ?? self::stringField($payload, 'doc_id') ?? 'a document';
        return "Document \"{$slug}\" was added to collection \"{$collection}\".";
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function stringField(array $payload, string $key): ?string
    {
        if (! array_key_exists($key, $payload)) {
            return null;
        }
        $value = $payload[$key];
        if ($value === null || $value === '') {
            return null;
        }
        return is_scalar($value) ? (string) $value : null;
    }
}

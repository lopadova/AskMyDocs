<?php

declare(strict_types=1);

namespace App\Support\TabularReview;

/**
 * v4.7/W1 — Format types for tabular-review columns.
 *
 * AskMyDocs ships 17 format types — Mike's 9 (text / bulleted_list /
 * number / percentage / monetary_amount / currency / yes_no / date /
 * tag) plus 8 AskMyDocs-new ones (enum / enum_status / rating / url /
 * person / tags_multi / relation / json_path). Each case contributes a
 * prompt suffix injected after the column instruction so the LLM
 * produces output the cell renderer + validator can trust.
 *
 * The `json_path` case is special — it is the LLM-FREE short-circuit
 * (cf. `isLlmFree()`): the extractor reads the value directly from the
 * document's chunk metadata via a JSON-path lookup (v4.5/W5.5 source-
 * aware ingestion). No LLM call is issued for those columns.
 *
 * R23: this enum is the single source of truth — adding a new format
 * requires:
 *   1. New case here.
 *   2. Suffix in `promptSuffix()`.
 *   3. New entry in the validator (controllers / FormRequests).
 *   4. New cell renderer (W3).
 *
 * Keeping the registry centralised avoids the supports()-overlap class
 * of bugs flagged by R23 — every format owns exactly one case.
 */
enum FormatType: string
{
    case TEXT = 'text';
    case BULLETED_LIST = 'bulleted_list';
    case NUMBER = 'number';
    case PERCENTAGE = 'percentage';
    case MONETARY_AMOUNT = 'monetary_amount';
    case CURRENCY = 'currency';
    case YES_NO = 'yes_no';
    case DATE = 'date';
    case TAG = 'tag';
    case ENUM = 'enum';
    case ENUM_STATUS = 'enum_status';
    case RATING = 'rating';
    case URL = 'url';
    case PERSON = 'person';
    case TAGS_MULTI = 'tags_multi';
    case RELATION = 'relation';
    case JSON_PATH = 'json_path';

    /**
     * Returns the prompt-suffix string injected after a column's
     * instruction. The wording is deliberately strict so the LLM
     * outputs something the validator can accept.
     *
     * For ENUM / ENUM_STATUS / TAGS_MULTI the caller passes the
     * configured option set so the prompt enumerates allowed values.
     *
     * @param  array<int, string>  $enumValues
     */
    public function promptSuffix(array $enumValues = []): string
    {
        return match ($this) {
            self::TEXT => 'Respond with a concise text answer (max 200 chars).',
            self::BULLETED_LIST => 'Respond as a markdown bulleted list with "- " items.',
            self::NUMBER => 'Respond with a single number, nothing else.',
            self::PERCENTAGE => 'Respond with a percentage in 0-100% (one decimal digit allowed).',
            self::MONETARY_AMOUNT => 'Respond with a monetary amount followed by its ISO-4217 currency code.',
            self::CURRENCY => 'Respond with a 3-letter ISO-4217 currency code (e.g. EUR, USD, GBP).',
            self::YES_NO => 'Answer Yes or No only.',
            self::DATE => 'Respond with an ISO-8601 date in YYYY-MM-DD format.',
            self::TAG => 'Respond with one short label (1-3 words).',
            self::ENUM => $this->enumPrompt($enumValues),
            self::ENUM_STATUS => $this->enumStatusPrompt($enumValues),
            self::RATING => 'Respond with a rating 1-5 (1=worst, 5=best).',
            self::URL => 'Respond with a single URL starting with https://.',
            self::PERSON => 'Respond with the person\'s email or full name.',
            self::TAGS_MULTI => $this->tagsMultiPrompt($enumValues),
            self::RELATION => 'Respond with the document title or doc-id from this knowledge base.',
            self::JSON_PATH => 'Respond with the value extracted from source metadata.',
        };
    }

    /**
     * True when this format MUST NOT issue an LLM call — value comes
     * from another source (e.g. chunk metadata via JSON Path).
     */
    public function isLlmFree(): bool
    {
        return $this === self::JSON_PATH;
    }

    /**
     * Return every enum value as a flat string list. Useful for
     * validators and for the route contract docs.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (FormatType $c) => $c->value, self::cases());
    }

    /**
     * @param  array<int, string>  $enumValues
     */
    private function enumPrompt(array $enumValues): string
    {
        if ($enumValues === []) {
            return 'Answer with exactly one allowed option.';
        }

        $list = implode(' / ', $enumValues);

        return "Answer with EXACTLY one of: {$list}.";
    }

    /**
     * @param  array<int, string>  $enumValues
     */
    private function enumStatusPrompt(array $enumValues): string
    {
        if ($enumValues === []) {
            return 'Answer with one of: todo / in_progress / done / blocked.';
        }

        $list = implode(' / ', $enumValues);

        return "Answer with one status from: {$list}.";
    }

    /**
     * @param  array<int, string>  $enumValues
     */
    private function tagsMultiPrompt(array $enumValues): string
    {
        if ($enumValues === []) {
            return 'Respond with multiple short labels separated by commas.';
        }

        $list = implode(' / ', $enumValues);

        return "Respond with multiple labels separated by commas. Allowed values: {$list}.";
    }
}

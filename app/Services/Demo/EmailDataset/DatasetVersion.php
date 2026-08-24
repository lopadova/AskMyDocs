<?php

declare(strict_types=1);

namespace App\Services\Demo\EmailDataset;

use InvalidArgumentException;

final class DatasetVersion
{
    public const GENERATOR_REVISION = 'g1';

    /** @var list<string> */
    private const HISTORICAL_GENERATOR_REVISIONS = ['g1'];

    public const DEFAULT_SEED = 20260723;

    public const DEFAULT_CATALOG_VERSION = 'v1';

    /** @var list<string> */
    public const PROFILES = ['gold', 'demo', 'large', 'stress'];

    /**
     * @param  list<string>  $companies
     * @param  list<string>  $mailboxes
     */
    public static function make(
        string $profile,
        int $seed,
        string $catalogVersion,
        array $companies = [],
        array $mailboxes = [],
        bool $subset = false,
        string $snapshotFingerprint = '',
    ): string {
        self::assertProfile($profile);

        if ($seed < 0) {
            throw new InvalidArgumentException('Dataset seed must be a non-negative integer.');
        }
        if (preg_match('/^[a-z0-9-]+$/', $catalogVersion) !== 1) {
            throw new InvalidArgumentException(
                "Invalid email dataset catalog version: {$catalogVersion}",
            );
        }
        if (preg_match('/^[a-f0-9]{64}$/', $snapshotFingerprint) !== 1) {
            throw new InvalidArgumentException(
                'Dataset snapshot fingerprint must be a lowercase SHA-256 hash.',
            );
        }

        $version = 'case-study-email-v2-'.self::GENERATOR_REVISION
            ."-{$profile}-s{$seed}-catalog{$catalogVersion}"
            .'-snap'.substr($snapshotFingerprint, 0, 16);

        if ($subset) {
            $version .= '-subset-'.substr(
                hash('sha256', implode(',', $companies).'|'.implode(',', $mailboxes)),
                0,
                10,
            );
        }

        return $version;
    }

    public static function standard(string $profile): string
    {
        self::assertProfile($profile);
        $catalogs = new CatalogLoader;
        $index = $catalogs->loadIndex(self::DEFAULT_CATALOG_VERSION);
        $companies = $index['companies'];
        $mailboxes = [];
        foreach ($companies as $companyKey) {
            $company = $catalogs->loadCompany(self::DEFAULT_CATALOG_VERSION, $companyKey);
            array_push($mailboxes, ...array_keys($company['mailboxes']));
        }
        sort($mailboxes, SORT_STRING);

        return self::make(
            $profile,
            self::DEFAULT_SEED,
            self::DEFAULT_CATALOG_VERSION,
            companies: $companies,
            mailboxes: $mailboxes,
            snapshotFingerprint: $catalogs->snapshotFingerprint(
                $profile,
                self::DEFAULT_CATALOG_VERSION,
                $companies,
                $mailboxes,
            ),
        );
    }

    public static function assertProfile(string $profile): void
    {
        if (! in_array($profile, self::PROFILES, true)) {
            throw new InvalidArgumentException(
                "Profilo dataset non valido: '{$profile}'.",
            );
        }
    }

    /**
     * Reader compatibility is intentionally broader than the revision used for
     * new artifacts. When GENERATOR_REVISION advances, already-published
     * immutable datasets remain readable until their revision is deliberately
     * removed from this historical allowlist.
     *
     * @return list<string>
     */
    public static function supportedGeneratorRevisions(
        ?string $currentRevision = null,
    ): array {
        $currentRevision ??= self::GENERATOR_REVISION;
        if (preg_match('/^g[1-9][0-9]*$/D', $currentRevision) !== 1) {
            throw new InvalidArgumentException(
                "Invalid email dataset generator revision: {$currentRevision}",
            );
        }

        $supported = self::HISTORICAL_GENERATOR_REVISIONS;
        if (! in_array($currentRevision, $supported, true)) {
            $supported[] = $currentRevision;
        }

        return $supported;
    }

    public static function supportsGeneratorRevision(
        string $revision,
        ?string $currentRevision = null,
    ): bool {
        if (preg_match('/^g[1-9][0-9]*$/D', $revision) !== 1) {
            return false;
        }

        return in_array(
            $revision,
            self::supportedGeneratorRevisions($currentRevision),
            true,
        );
    }

    public static function hasGeneratorRevisionPrefix(
        string $datasetVersion,
        string $revision,
    ): bool {
        return str_starts_with(
            $datasetVersion,
            'case-study-email-v2-'.$revision.'-',
        );
    }
}

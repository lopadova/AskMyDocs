<?php

declare(strict_types=1);

namespace App\Services\Demo\EmailDataset;

use InvalidArgumentException;
use JsonException;
use RuntimeException;

final readonly class CatalogLoader
{
    private const SUPPORTED_SCHEMA_VERSION = '2.0';

    public function __construct(private ?string $rootDirectory = null) {}

    public function loadProfile(string $profile): DatasetProfile
    {
        $this->assertKey($profile, 'profile');
        $data = $this->readJson($this->root().'/profiles/'.$profile.'.json');
        $loaded = DatasetProfile::fromArray($data);

        if ($loaded->key !== $profile) {
            throw new RuntimeException("Profile filename {$profile} does not match embedded key {$loaded->key}.");
        }

        return $loaded;
    }

    /**
     * Commits the immutable generator inputs into the dataset identity.
     *
     * @param  list<string>  $companies
     * @param  list<string>  $mailboxes
     */
    public function snapshotFingerprint(
        string $profile,
        string $catalogVersion,
        array $companies,
        array $mailboxes,
    ): string {
        $loadedProfile = $this->loadProfile($profile);
        $index = $this->loadIndex($catalogVersion);
        $companies = array_values(array_unique(array_map('strval', $companies)));
        $mailboxes = array_values(array_unique(array_map('strval', $mailboxes)));
        sort($companies, SORT_STRING);
        sort($mailboxes, SORT_STRING);

        if ($companies === [] || $mailboxes === []) {
            throw new InvalidArgumentException(
                'Dataset snapshot fingerprint requires at least one company and mailbox.',
            );
        }

        $unknownCompanies = array_values(array_diff($companies, $index['companies']));
        if ($unknownCompanies !== []) {
            throw new InvalidArgumentException(
                'Dataset snapshot references unknown companies: '.implode(', ', $unknownCompanies),
            );
        }

        $paths = [
            $this->root().'/profiles/'.$profile.'.json',
            $this->root().'/schema/email-v2.schema.json',
            $this->catalogDirectory($catalogVersion).'/catalog.json',
        ];
        $availableMailboxes = [];
        foreach ($companies as $companyKey) {
            $company = $this->loadCompany($catalogVersion, $companyKey);
            array_push($availableMailboxes, ...array_keys($company['mailboxes']));
            $directory = $this->catalogDirectory($catalogVersion).'/companies/'.$companyKey;
            foreach (['canaries.json', 'facts.json', 'personas.json', 'scenarios.json'] as $filename) {
                $paths[] = $directory.'/'.$filename;
            }
        }

        $unknownMailboxes = array_values(array_diff($mailboxes, $availableMailboxes));
        if ($unknownMailboxes !== []) {
            throw new InvalidArgumentException(
                'Dataset snapshot references unknown mailboxes: '.implode(', ', $unknownMailboxes),
            );
        }

        if ($loadedProfile->includeGold) {
            foreach ($mailboxes as $mailboxKey) {
                $paths[] = $this->goldPath($mailboxKey);
            }
        }

        $inputs = [];
        foreach ($paths as $path) {
            $label = $this->snapshotLabel($path);
            if (isset($inputs[$label])) {
                throw new RuntimeException("Duplicate dataset snapshot input label {$label}.");
            }
            $inputs[$label] = $path;
        }
        ksort($inputs, SORT_STRING);

        $parts = ['generator_revision '.DatasetVersion::GENERATOR_REVISION];
        foreach ($inputs as $label => $path) {
            $checksum = hash_file('sha256', $path);
            if ($checksum === false) {
                throw new RuntimeException("Unable to checksum dataset snapshot input {$path}.");
            }

            $parts[] = $label.' '.$checksum;
        }

        return hash('sha256', implode("\n", $parts)."\n");
    }

    /**
     * @return array{schema_version: string, catalog_version: string, companies: list<string>}
     */
    public function loadIndex(string $catalogVersion): array
    {
        $directory = $this->catalogDirectory($catalogVersion);
        if (! is_dir($directory)) {
            throw new InvalidArgumentException("Catalog version {$catalogVersion} is unavailable.");
        }

        $index = $this->readJson($directory.'/catalog.json');
        $declaredVersion = $index['catalog_version'] ?? null;

        if (! is_string($declaredVersion) || $declaredVersion !== $catalogVersion) {
            throw new RuntimeException(
                "Catalog snapshot {$catalogVersion} declares version "
                .(is_scalar($declaredVersion) ? (string) $declaredVersion : 'unknown').'.'
            );
        }

        if (($index['schema_version'] ?? null) !== self::SUPPORTED_SCHEMA_VERSION) {
            throw new RuntimeException(
                "Catalog snapshot {$catalogVersion} must use schema version "
                .self::SUPPORTED_SCHEMA_VERSION.'.'
            );
        }

        $companies = $index['companies'] ?? null;
        if (! is_array($companies) || ! array_is_list($companies) || $companies === []) {
            throw new RuntimeException('Catalog index must contain a non-empty companies list.');
        }

        $companyKeys = [];
        foreach ($companies as $company) {
            if (! is_string($company)) {
                throw new RuntimeException("Catalog snapshot {$catalogVersion} contains a non-string company key.");
            }

            $this->assertKey($company, 'company');
            if (in_array($company, $companyKeys, true)) {
                throw new RuntimeException("Catalog snapshot {$catalogVersion} contains duplicate company {$company}.");
            }

            $companyKeys[] = $company;
        }
        sort($companyKeys, SORT_STRING);

        return [
            'schema_version' => self::SUPPORTED_SCHEMA_VERSION,
            'catalog_version' => $declaredVersion,
            'companies' => $companyKeys,
        ];
    }

    /**
     * @return array{
     *     company_key: string,
     *     company_name: string,
     *     mailboxes: array<string, array{to_email: string, category_matrix_large: array<string, int>}>,
     *     scenarios: array<string, array<string, mixed>>,
     *     personas: array<string, array<string, mixed>>,
     *     facts: array<string, array<string, mixed>>,
     *     canaries: list<array<string, mixed>>
     * }
     */
    public function loadCompany(string $catalogVersion, string $companyKey): array
    {
        $index = $this->loadIndex($catalogVersion);
        $this->assertKey($companyKey, 'company');

        if (! in_array($companyKey, $index['companies'], true)) {
            throw new InvalidArgumentException("Unknown company in email catalog: {$companyKey}");
        }

        $directory = $this->catalogDirectory($catalogVersion).'/companies/'.$companyKey;
        $scenarioData = $this->readJson($directory.'/scenarios.json');
        $personaData = $this->readJson($directory.'/personas.json');
        $factData = $this->readJson($directory.'/facts.json');
        $canaryData = $this->readJson($directory.'/canaries.json');

        foreach ([$scenarioData, $personaData, $factData, $canaryData] as $data) {
            if (($data['company_key'] ?? null) !== $companyKey) {
                throw new RuntimeException("Catalog file company_key does not match {$companyKey}.");
            }
        }

        $personas = $this->indexRecords($personaData['personas'] ?? null, 'persona_id', "{$companyKey} personas");
        $facts = $this->indexRecords($factData['facts'] ?? null, 'fact_id', "{$companyKey} facts");
        $scenarios = $scenarioData['scenarios'] ?? null;
        $mailboxes = $scenarioData['mailboxes'] ?? null;
        $canaries = $canaryData['canaries'] ?? null;

        if (! is_array($scenarios) || $scenarios === [] || ! is_array($mailboxes) || $mailboxes === []) {
            throw new RuntimeException("Company {$companyKey} must define scenarios and mailboxes.");
        }

        if (! is_array($canaries) || ! array_is_list($canaries)) {
            throw new RuntimeException("Company {$companyKey} canaries must be a list.");
        }

        ksort($scenarios, SORT_STRING);
        ksort($mailboxes, SORT_STRING);

        foreach ($scenarios as $scenarioKey => $scenario) {
            $this->assertKey((string) $scenarioKey, 'scenario');
            if (! is_array($scenario)) {
                throw new RuntimeException("Scenario {$scenarioKey} must be an object.");
            }

            foreach ((array) ($scenario['persona_ids'] ?? []) as $personaId) {
                if (! isset($personas[(string) $personaId])) {
                    throw new RuntimeException("Scenario {$scenarioKey} references unknown persona {$personaId}.");
                }
            }

            foreach ((array) ($scenario['fact_ids'] ?? []) as $factId) {
                if (! isset($facts[(string) $factId])) {
                    throw new RuntimeException("Scenario {$scenarioKey} references unknown fact {$factId}.");
                }
            }
        }

        foreach ($mailboxes as $mailboxKey => &$mailbox) {
            $this->assertKey((string) $mailboxKey, 'mailbox');
            if (! is_array($mailbox) || ! isset($mailbox['to_email'], $mailbox['category_matrix_large'])) {
                throw new RuntimeException("Mailbox {$mailboxKey} has an invalid catalog contract.");
            }

            $matrix = $mailbox['category_matrix_large'];
            if (! is_array($matrix) || array_sum($matrix) !== 1000) {
                throw new RuntimeException("Mailbox {$mailboxKey} large matrix must sum to 1,000.");
            }

            foreach ($matrix as $scenarioKey => $count) {
                if (! isset($scenarios[$scenarioKey]) || ! is_int($count) || $count < 0) {
                    throw new RuntimeException("Mailbox {$mailboxKey} has an invalid scenario allocation.");
                }
            }

            ksort($mailbox['category_matrix_large'], SORT_STRING);
        }
        unset($mailbox);

        foreach ($canaries as $canary) {
            if (! is_array($canary) || ! isset($canary['canary_id'], $canary['phrase'])) {
                throw new RuntimeException("Company {$companyKey} contains an invalid canary.");
            }

            foreach ((array) ($canary['scenario_types'] ?? []) as $scenarioKey) {
                if (! isset($scenarios[(string) $scenarioKey])) {
                    throw new RuntimeException("Canary {$canary['canary_id']} references unknown scenario {$scenarioKey}.");
                }
            }
        }

        return [
            'company_key' => $companyKey,
            'company_name' => (string) ($scenarioData['company_name'] ?? $companyKey),
            'mailboxes' => $mailboxes,
            'scenarios' => $scenarios,
            'personas' => $personas,
            'facts' => $facts,
            'canaries' => $canaries,
        ];
    }

    public function goldPath(string $mailboxKey): string
    {
        $this->assertKey($mailboxKey, 'mailbox');

        return $this->projectRoot().'/database/seeders/emails/'.$mailboxKey.'.json';
    }

    /**
     * The historical gold layer is deliberately bounded (119-136 records per
     * mailbox), so decoding one legacy JSON array does not scale with generated
     * profile size. Every generated record is streamed separately.
     *
     * @return list<array<string, mixed>>
     */
    public function loadGold(string $mailboxKey): array
    {
        $records = $this->readJson($this->goldPath($mailboxKey));
        if (! array_is_list($records)) {
            throw new RuntimeException("Gold fixture {$mailboxKey} must be a JSON list.");
        }

        return $records;
    }

    private function root(): string
    {
        return rtrim(
            $this->rootDirectory ?? $this->projectRoot().'/database/seeders/email-dataset',
            DIRECTORY_SEPARATOR,
        );
    }

    private function catalogDirectory(string $catalogVersion): string
    {
        $this->assertKey($catalogVersion, 'catalog version');

        return $this->root().'/catalogs/'.$catalogVersion;
    }

    private function projectRoot(): string
    {
        return dirname(__DIR__, 4);
    }

    private function snapshotLabel(string $path): string
    {
        $projectPrefix = rtrim($this->projectRoot(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        if (str_starts_with($path, $projectPrefix)) {
            return substr($path, strlen($projectPrefix));
        }

        $catalogPrefix = rtrim($this->root(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        if (str_starts_with($path, $catalogPrefix)) {
            return 'email-dataset/'.substr($path, strlen($catalogPrefix));
        }

        throw new RuntimeException("Dataset snapshot input is outside approved roots: {$path}");
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("Email dataset catalog does not exist: {$path}");
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("Unable to read email dataset catalog: {$path}");
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("Invalid JSON in email dataset catalog {$path}: {$exception->getMessage()}", 0, $exception);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException("Email dataset catalog must decode to an object or list: {$path}");
        }

        return $decoded;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function indexRecords(mixed $records, string $idField, string $label): array
    {
        if (! is_array($records) || ! array_is_list($records) || $records === []) {
            throw new RuntimeException("{$label} must be a non-empty list.");
        }

        $indexed = [];
        foreach ($records as $record) {
            if (! is_array($record) || ! isset($record[$idField])) {
                throw new RuntimeException("{$label} contains a record without {$idField}.");
            }

            $id = (string) $record[$idField];
            if (isset($indexed[$id])) {
                throw new RuntimeException("{$label} contains duplicate {$idField} {$id}.");
            }
            $indexed[$id] = $record;
        }

        ksort($indexed, SORT_STRING);

        return $indexed;
    }

    private function assertKey(string $key, string $label): void
    {
        if (! preg_match('/^[a-z0-9-]+$/', $key)) {
            throw new InvalidArgumentException("Invalid {$label} key: {$key}");
        }
    }
}

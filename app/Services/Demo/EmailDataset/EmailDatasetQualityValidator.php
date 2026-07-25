<?php

declare(strict_types=1);

namespace App\Services\Demo\EmailDataset;

use DateTimeImmutable;
use JsonException;
use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;
use Throwable;

/**
 * Cross-record quality gates that cannot be proven by the JSON schema alone.
 *
 * The stress profile contains 30k rows. Corpus-sized identity and thread
 * indexes therefore live in a temporary SQLite database; PHP retains only
 * bounded catalog counters and one supported-size thread at a time.
 */
final readonly class EmailDatasetQualityValidator
{
    public function __construct(
        private EmailDatasetReader $reader,
        private ?CatalogLoader $catalogs = null,
        private ?string $temporaryDirectory = null,
    ) {}

    public function validate(string $datasetDirectory): void
    {
        $manifest = $this->reader->manifest($datasetDirectory);
        $catalogs = $this->catalogs ?? new CatalogLoader;
        $companyCatalogs = [];
        $canaryPhrases = [];
        foreach ((array) ($manifest['selection']['companies'] ?? []) as $companyKey) {
            $companyKey = (string) $companyKey;
            $company = $catalogs->loadCompany(
                (string) $manifest['catalog_version'],
                $companyKey,
            );
            $companyCatalogs[$companyKey] = $company;

            foreach ((array) $company['canaries'] as $canary) {
                if (! is_array($canary)) {
                    continue;
                }

                $phrase = (string) ($canary['phrase'] ?? '');
                if ($phrase === '') {
                    continue;
                }

                $canaryPhrases[] = [
                    'company_key' => $companyKey,
                    'canary_id' => (string) ($canary['canary_id'] ?? ''),
                    'phrase' => $phrase,
                ];
            }
        }

        $records = 0;
        $companyCounts = [];
        $mailboxCounts = [];
        $messageTypeCounts = [];
        $truthCounts = [];
        [$index, $indexPath] = $this->createIndex();

        try {
            $identityInsert = $this->prepare(
                $index,
                <<<'SQL'
                    INSERT INTO identities (
                        fixture_id,
                        message_id,
                        generated_content_hash
                    ) VALUES (
                        :fixture_id,
                        :message_id,
                        :generated_content_hash
                    )
                    SQL,
            );
            $threadInsert = $this->prepare(
                $index,
                <<<'SQL'
                    INSERT INTO thread_messages (
                        thread_id,
                        fixture_id,
                        message_id,
                        in_reply_to,
                        references_json,
                        sent_at,
                        company_key,
                        mailbox_key,
                        scenario_type,
                        truth_state
                    ) VALUES (
                        :thread_id,
                        :fixture_id,
                        :message_id,
                        :in_reply_to,
                        :references_json,
                        :sent_at,
                        :company_key,
                        :mailbox_key,
                        :scenario_type,
                        :truth_state
                    )
                    SQL,
            );

            if (! $index->beginTransaction()) {
                throw new RuntimeException('Unable to begin the email dataset quality-index transaction.');
            }

            try {
                foreach ($this->reader->records($datasetDirectory) as $record) {
                    $records++;
                    $fixtureId = (string) $record['fixture_id'];
                    $messageId = (string) $record['message_id'];
                    $companyKey = (string) $record['company_key'];
                    $mailboxKey = (string) $record['mailbox_key'];
                    $messageType = (string) $record['message_type'];
                    $truthState = (string) $record['truth_state'];
                    $generatedContentHash = $messageType === 'gold'
                        ? null
                        : hash('sha256', (string) $record['subject']."\0".(string) $record['body_text']);

                    $this->indexIdentity(
                        $index,
                        $identityInsert,
                        $fixtureId,
                        $messageId,
                        $generatedContentHash,
                    );

                    $company = $companyCatalogs[$companyKey] ?? null;
                    if (! is_array($company)) {
                        throw new RuntimeException("Fixture {$fixtureId} references an unselected company {$companyKey}.");
                    }
                    $this->assertCatalogReferences($record, $company);

                    $this->increment($companyCounts, $companyKey);
                    $this->increment($mailboxCounts, $mailboxKey);
                    $this->increment($messageTypeCounts, $messageType);
                    $this->increment($truthCounts, $truthState);

                    $this->assertCanaryOwnership($record);
                    $this->assertCanaryPhraseIsolation($record, $canaryPhrases);

                    if ($messageType !== 'thread') {
                        if (($record['in_reply_to'] ?? null) !== null || ($record['references'] ?? []) !== []) {
                            throw new RuntimeException("Standalone fixture {$fixtureId} contains thread headers.");
                        }

                        continue;
                    }

                    if (! $threadInsert->execute([
                        'thread_id' => (string) $record['thread_id'],
                        'fixture_id' => $fixtureId,
                        'message_id' => $messageId,
                        'in_reply_to' => $record['in_reply_to'],
                        'references_json' => json_encode($record['references'], JSON_THROW_ON_ERROR),
                        'sent_at' => (string) $record['sent_at'],
                        'company_key' => $companyKey,
                        'mailbox_key' => $mailboxKey,
                        'scenario_type' => (string) $record['scenario_type'],
                        'truth_state' => $truthState,
                    ])) {
                        throw new RuntimeException("Unable to index thread fixture {$fixtureId}.");
                    }
                }
            } catch (Throwable $exception) {
                if ($index->inTransaction() && ! $index->rollBack()) {
                    throw new RuntimeException(
                        'Unable to roll back the email dataset quality-index transaction.',
                        0,
                        $exception,
                    );
                }

                throw $exception;
            }

            if (! $index->commit()) {
                throw new RuntimeException('Unable to commit the email dataset quality-index transaction.');
            }

            if ($records !== (int) $manifest['total_records']) {
                throw new RuntimeException(
                    "Quality scan read {$records} records; manifest declares {$manifest['total_records']}.",
                );
            }

            $statistics = (array) ($manifest['statistics'] ?? []);
            $this->assertCounter('company', $companyCounts, $statistics['records_by_company'] ?? null);
            $this->assertCounter('mailbox', $mailboxCounts, $statistics['records_by_mailbox'] ?? null);
            $this->assertCounter('message type', $messageTypeCounts, $statistics['records_by_message_type'] ?? null);
            $this->assertCounter('truth state', $truthCounts, $statistics['records_by_truth_state'] ?? null);
            $this->assertIndexedThreads($index);
        } finally {
            $index = null;
            if (is_file($indexPath) && ! unlink($indexPath)) {
                throw new RuntimeException("Unable to remove temporary email quality index {$indexPath}.");
            }
        }
    }

    /**
     * @return array{PDO, string}
     */
    private function createIndex(): array
    {
        if (! in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            throw new RuntimeException('The pdo_sqlite extension is required to validate email datasets.');
        }

        $directory = $this->temporaryDirectory ?? sys_get_temp_dir();
        if (! is_dir($directory) || ! is_writable($directory)) {
            throw new RuntimeException("Email quality-index directory is not writable: {$directory}");
        }

        $path = tempnam($directory, 'askmydocs-email-quality-');
        if ($path === false) {
            throw new RuntimeException("Unable to allocate an email quality index in {$directory}.");
        }

        $index = null;
        try {
            $index = new PDO(
                'sqlite:'.$path,
                null,
                null,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ],
            );
            $this->execute($index, 'PRAGMA temp_store = FILE');
            $this->execute($index, 'PRAGMA cache_size = -2048');
            $this->execute(
                $index,
                <<<'SQL'
                    CREATE TABLE identities (
                        fixture_id TEXT NOT NULL PRIMARY KEY,
                        message_id TEXT NOT NULL UNIQUE,
                        generated_content_hash TEXT UNIQUE
                    ) WITHOUT ROWID
                    SQL,
            );
            $this->execute(
                $index,
                <<<'SQL'
                    CREATE TABLE thread_messages (
                        thread_id TEXT NOT NULL,
                        fixture_id TEXT NOT NULL,
                        message_id TEXT NOT NULL,
                        in_reply_to TEXT,
                        references_json TEXT NOT NULL,
                        sent_at TEXT NOT NULL,
                        company_key TEXT NOT NULL,
                        mailbox_key TEXT NOT NULL,
                        scenario_type TEXT NOT NULL,
                        truth_state TEXT NOT NULL
                    )
                    SQL,
            );
            $this->execute(
                $index,
                <<<'SQL'
                    CREATE INDEX thread_messages_quality_order
                    ON thread_messages (thread_id, sent_at, fixture_id)
                    SQL,
            );

            return [$index, $path];
        } catch (Throwable $exception) {
            $index = null;
            if (is_file($path) && ! unlink($path)) {
                throw new RuntimeException(
                    "Unable to remove failed temporary email quality index {$path}.",
                    0,
                    $exception,
                );
            }

            throw $exception;
        }
    }

    private function execute(PDO $index, string $sql): void
    {
        if ($index->exec($sql) === false) {
            throw new RuntimeException('Unable to initialize the temporary email quality index.');
        }
    }

    private function prepare(PDO $index, string $sql): PDOStatement
    {
        $statement = $index->prepare($sql);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare an email quality-index statement.');
        }

        return $statement;
    }

    private function indexIdentity(
        PDO $index,
        PDOStatement $statement,
        string $fixtureId,
        string $messageId,
        ?string $generatedContentHash,
    ): void {
        try {
            if (! $statement->execute([
                'fixture_id' => $fixtureId,
                'message_id' => $messageId,
                'generated_content_hash' => $generatedContentHash,
            ])) {
                throw new RuntimeException("Unable to index email fixture {$fixtureId}.");
            }
        } catch (PDOException $exception) {
            if ($this->indexContains($index, 'fixture_id', $fixtureId)) {
                throw new RuntimeException("Duplicate email fixture_id: {$fixtureId}", 0, $exception);
            }
            if ($this->indexContains($index, 'message_id', $messageId)) {
                throw new RuntimeException("Duplicate email Message-ID: {$messageId}", 0, $exception);
            }
            if (
                $generatedContentHash !== null
                && $this->indexContains($index, 'generated_content_hash', $generatedContentHash)
            ) {
                throw new RuntimeException(
                    "Duplicate generated subject+body pair at fixture {$fixtureId}.",
                    0,
                    $exception,
                );
            }

            throw $exception;
        }
    }

    private function indexContains(PDO $index, string $column, string $value): bool
    {
        if (! in_array($column, ['fixture_id', 'message_id', 'generated_content_hash'], true)) {
            throw new RuntimeException("Unsupported email quality-index lookup column {$column}.");
        }

        $statement = $this->prepare(
            $index,
            "SELECT 1 FROM identities WHERE {$column} = :value LIMIT 1",
        );
        if (! $statement->execute(['value' => $value])) {
            throw new RuntimeException('Unable to query the temporary email quality index.');
        }

        return $statement->fetchColumn() !== false;
    }

    private function assertIndexedThreads(PDO $index): void
    {
        $statement = $index->query(
            <<<'SQL'
                SELECT
                    thread_id,
                    fixture_id,
                    message_id,
                    in_reply_to,
                    references_json,
                    sent_at,
                    company_key,
                    mailbox_key,
                    scenario_type,
                    truth_state
                FROM thread_messages
                ORDER BY thread_id, sent_at, fixture_id
                SQL,
        );
        if ($statement === false) {
            throw new RuntimeException('Unable to stream the temporary email thread index.');
        }

        $currentThreadId = null;
        $messages = [];
        while (($row = $statement->fetch()) !== false) {
            $threadId = (string) $row['thread_id'];
            if ($currentThreadId !== null && $threadId !== $currentThreadId) {
                $this->assertThread($currentThreadId, $messages);
                $messages = [];
            }

            $currentThreadId = $threadId;
            $references = $this->decodeReferences(
                (string) $row['references_json'],
                (string) $row['fixture_id'],
            );
            $messages[] = [
                'fixture_id' => (string) $row['fixture_id'],
                'message_id' => (string) $row['message_id'],
                'in_reply_to' => $row['in_reply_to'],
                'references' => $references,
                'sent_at' => (string) $row['sent_at'],
                'company_key' => (string) $row['company_key'],
                'mailbox_key' => (string) $row['mailbox_key'],
                'scenario_type' => (string) $row['scenario_type'],
                'truth_state' => (string) $row['truth_state'],
            ];

            if (count($messages) > 8) {
                throw new RuntimeException(
                    "Thread {$threadId} has unsupported length greater than 8.",
                );
            }
        }

        if ($currentThreadId !== null) {
            $this->assertThread($currentThreadId, $messages);
        }
    }

    /**
     * @return list<string>
     */
    private function decodeReferences(string $json, string $fixtureId): array
    {
        try {
            $references = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                "Temporary email thread index contains invalid References for fixture {$fixtureId}.",
                0,
                $exception,
            );
        }

        if (! is_array($references) || ! array_is_list($references)) {
            throw new RuntimeException(
                "Temporary email thread index contains invalid References for fixture {$fixtureId}.",
            );
        }

        return array_values(array_map('strval', $references));
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     */
    private function assertThread(string $threadId, array $messages): void
    {
        usort(
            $messages,
            static fn (array $left, array $right): int => strcmp(
                (string) $left['sent_at'],
                (string) $right['sent_at'],
            ),
        );

        if (! in_array(count($messages), [2, 3, 4, 5, 8], true)) {
            throw new RuntimeException(
                "Thread {$threadId} has unsupported length ".count($messages).'.',
            );
        }

        $company = (string) $messages[0]['company_key'];
        $mailbox = (string) $messages[0]['mailbox_key'];
        $scenario = (string) $messages[0]['scenario_type'];
        $chain = [];
        $previousDate = null;

        foreach ($messages as $position => $message) {
            if (
                $message['company_key'] !== $company
                || $message['mailbox_key'] !== $mailbox
                || $message['scenario_type'] !== $scenario
            ) {
                throw new RuntimeException("Thread {$threadId} crosses a company, mailbox or scenario boundary.");
            }

            $date = new DateTimeImmutable((string) $message['sent_at']);
            if ($previousDate !== null && $date <= $previousDate) {
                throw new RuntimeException("Thread {$threadId} dates are not strictly increasing.");
            }

            if ($position === 0) {
                if ($message['in_reply_to'] !== null || $message['references'] !== []) {
                    throw new RuntimeException("Thread {$threadId} root contains reply headers.");
                }
            } else {
                $expectedParent = $chain[array_key_last($chain)];
                if ($message['in_reply_to'] !== $expectedParent) {
                    throw new RuntimeException("Thread {$threadId} has an orphan or non-contiguous reply.");
                }
                if ($message['references'] !== array_values($chain)) {
                    throw new RuntimeException("Thread {$threadId} has an invalid References chain.");
                }
            }

            $chain[] = (string) $message['message_id'];
            $previousDate = $date;
        }

        if ($scenario === 'correzioni-rumore') {
            if ($messages[0]['truth_state'] !== 'incorrect' || $messages[array_key_last($messages)]['truth_state'] !== 'corrected') {
                throw new RuntimeException(
                    "Correction thread {$threadId} must progress from incorrect to corrected.",
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function assertCanaryOwnership(array $record): void
    {
        $companyPrefix = explode('-', (string) $record['company_key'], 2)[0];
        foreach ($record['canary_ids'] as $canaryId) {
            if (! is_string($canaryId) || ! str_starts_with($canaryId, $companyPrefix.'.canary.')) {
                throw new RuntimeException(
                    "Cross-company canary {$canaryId} in fixture {$record['fixture_id']}.",
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  list<array{company_key: string, canary_id: string, phrase: string}>  $canaryPhrases
     */
    private function assertCanaryPhraseIsolation(array $record, array $canaryPhrases): void
    {
        $companyKey = (string) $record['company_key'];
        $body = (string) $record['body_text'];

        foreach ($canaryPhrases as $canary) {
            if (
                $canary['company_key'] !== $companyKey
                && str_contains($body, $canary['phrase'])
            ) {
                throw new RuntimeException(
                    "Cross-company canary phrase {$canary['canary_id']} in fixture {$record['fixture_id']}.",
                );
            }
        }
    }

    /**
     * @param  array<string,mixed>  $record
     * @param  array<string,mixed>  $company
     */
    private function assertCatalogReferences(array $record, array $company): void
    {
        $fixtureId = (string) $record['fixture_id'];
        $mailboxKey = (string) $record['mailbox_key'];
        $scenarioKey = (string) $record['scenario_type'];
        $mailbox = $company['mailboxes'][$mailboxKey] ?? null;
        $scenario = $company['scenarios'][$scenarioKey] ?? null;

        if (! is_array($mailbox) || ! is_array($scenario)) {
            throw new RuntimeException(
                "Fixture {$fixtureId} references an unknown mailbox or scenario.",
            );
        }

        if ($record['to'] !== [(string) $mailbox['to_email']]) {
            throw new RuntimeException("Fixture {$fixtureId} has recipients outside its mailbox catalog.");
        }

        $expectedFactIds = array_values(array_map('strval', (array) $scenario['fact_ids']));
        $actualFactIds = array_values(array_map('strval', (array) $record['fact_ids']));
        if ($actualFactIds !== $expectedFactIds) {
            throw new RuntimeException("Fixture {$fixtureId} fact_ids drift from its scenario catalog.");
        }

        $expectedSources = [];
        foreach ($expectedFactIds as $factId) {
            $fact = $company['facts'][$factId] ?? null;
            if (! is_array($fact)) {
                throw new RuntimeException("Fixture {$fixtureId} references unknown fact {$factId}.");
            }

            $source = (string) ($fact['canonical_source'] ?? '');
            if (! str_starts_with(
                $source,
                'case-studies/'.(string) $record['company_key'].'/',
            )) {
                throw new RuntimeException("Fixture {$fixtureId} has a cross-company canonical source.");
            }
            $expectedSources[] = $source;
        }
        $expectedSources = array_values(array_unique($expectedSources));
        if ($record['canonical_sources'] !== $expectedSources) {
            throw new RuntimeException("Fixture {$fixtureId} canonical_sources drift from its facts.");
        }

        if (($record['message_type'] ?? null) !== 'gold') {
            $allowedPersonaIds = array_map('strval', (array) $scenario['persona_ids']);
            $matchesPersona = false;
            foreach ($allowedPersonaIds as $personaId) {
                $persona = $company['personas'][$personaId] ?? null;
                if (
                    is_array($persona)
                    && ($persona['name'] ?? null) === $record['from_name']
                    && ($persona['email'] ?? null) === $record['from_email']
                ) {
                    $matchesPersona = true;
                    break;
                }
            }
            if (! $matchesPersona) {
                throw new RuntimeException("Fixture {$fixtureId} sender is absent from its scenario personas.");
            }
        }

        $canaries = [];
        foreach ((array) $company['canaries'] as $canary) {
            if (is_array($canary) && isset($canary['canary_id'])) {
                $canaries[(string) $canary['canary_id']] = $canary;
            }
        }
        foreach ($record['canary_ids'] as $canaryId) {
            $canary = $canaries[(string) $canaryId] ?? null;
            if (
                ! is_array($canary)
                || ! in_array($scenarioKey, array_map('strval', (array) ($canary['scenario_types'] ?? [])), true)
                || ! str_contains((string) $record['body_text'], (string) ($canary['phrase'] ?? ''))
            ) {
                throw new RuntimeException("Fixture {$fixtureId} has an invalid or missing canary.");
            }
        }
    }

    /**
     * @param  array<string,int>  $counter
     */
    private function increment(array &$counter, string $key): void
    {
        $counter[$key] = ($counter[$key] ?? 0) + 1;
    }

    /**
     * @param  array<string,int>  $actual
     */
    private function assertCounter(string $label, array $actual, mixed $declared): void
    {
        if (! is_array($declared)) {
            throw new RuntimeException("Manifest is missing {$label} statistics.");
        }

        ksort($actual, SORT_STRING);
        $expected = array_map('intval', $declared);
        ksort($expected, SORT_STRING);

        if ($actual !== $expected) {
            throw new RuntimeException("Manifest {$label} statistics do not match the records.");
        }
    }
}

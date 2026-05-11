<?php

declare(strict_types=1);

namespace Tests\Live\Connectors;

use Tests\Live\Support\HttpResponseRecorder;
use Tests\TestCase;

/**
 * Base class for the v4.5/W5.5 live connector test suite.
 *
 * Live tests hit the real vendor API (Notion, Google Drive, Confluence,
 * etc.) so the chunkers and frontmatter-capture code can be developed
 * against the actual response shape — not a hand-fabricated payload
 * that drifts the moment the vendor changes a field.
 *
 * Two gates protect the live tests from firing in CI:
 *
 *   1. The class-level env var `CONNECTOR_<SOURCE>_LIVE` must equal '1'
 *      (e.g. `CONNECTOR_NOTION_LIVE=1`). Defaults to off so CI runs
 *      skip the whole suite.
 *
 *   2. The class-level required credential env vars (one per provider)
 *      must be present. The operator runbook
 *      docs/v4-platform/RUNBOOK-live-fixture-recording.md walks through
 *      how to obtain each set of test credentials.
 *
 * When recording mode is on (CONNECTOR_RECORD_FIXTURES=1), the
 * HttpResponseRecorder middleware persists every response body to
 * `tests/fixtures/connectors/<source>/recorded/...` AFTER scrubbing
 * PII, internal IDs, and bearer tokens. The committed recorded
 * fixtures are the baseline the chunker tests load via
 * JsonFixtureLoader.
 *
 * Live tests are wired into a NEW workflow `live-recording-nightly.yml`
 * that is `workflow_dispatch`-only — operators trigger it manually
 * after pointing repo secrets at fresh test-tenant credentials.
 */
abstract class LiveConnectorTestCase extends TestCase
{
    /** @return string The env-var name that gates this provider's live suite. */
    abstract protected static function gateEnvVar(): string;

    /** @return list<string> Required credential env-vars; missing any → skip. */
    abstract protected static function requiredCredentialEnvVars(): array;

    /** @return string Short provider slug used in fixture paths (notion, google_drive, ...). */
    abstract protected static function providerSlug(): string;

    protected function setUp(): void
    {
        parent::setUp();

        // R39 — gate the entire class behind both the on-switch and the
        // credentials. Failing fast with a skip beats letting the first
        // test throw a confusing 401.
        if (getenv(static::gateEnvVar()) !== '1') {
            $this->markTestSkipped(static::gateEnvVar() . ' not set to 1 — live suite disabled.');
        }

        foreach (static::requiredCredentialEnvVars() as $envVar) {
            if (getenv($envVar) === false || trim((string) getenv($envVar)) === '') {
                $this->markTestSkipped("Missing credential env var: {$envVar}");
            }
        }

        // Enable HTTP recording for THIS test run when the operator
        // explicitly requested it. The recorder is a global Http
        // middleware so it captures every Laravel HTTP client call
        // regardless of which connector fired it.
        if (getenv('CONNECTOR_RECORD_FIXTURES') === '1') {
            HttpResponseRecorder::enable(static::providerSlug());
        }
    }

    protected function tearDown(): void
    {
        HttpResponseRecorder::disable();
        parent::tearDown();
    }

    /**
     * Path under `tests/fixtures/connectors/<provider>/`.
     */
    protected function fixturePath(string $relative): string
    {
        return dirname(__DIR__, 2)
            . DIRECTORY_SEPARATOR . 'Fixtures'
            . DIRECTORY_SEPARATOR . 'connectors'
            . DIRECTORY_SEPARATOR . static::providerSlug()
            . DIRECTORY_SEPARATOR . ltrim($relative, '/\\');
    }
}

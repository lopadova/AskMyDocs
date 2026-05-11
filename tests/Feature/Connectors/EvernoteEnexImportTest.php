<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Connectors\BuiltIn\Evernote\EnexImporter;
use App\Connectors\BuiltIn\Evernote\InvalidEnexException;
use App\Jobs\IngestDocumentJob;
use App\Models\ConnectorInstallation;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * v4.5/W4 — `.enex` bulk import flow.
 *
 * Two layers covered:
 *   1. Direct EnexImporter unit-style tests against a fixture file
 *      built inline (3 notes / malformed XML / non-en-export root).
 *   2. HTTP layer end-to-end through
 *      POST /api/admin/connectors/evernote/import-enex with the
 *      manageConnectors gate granted.
 */
final class EvernoteEnexImportTest extends TestCase
{
    use RefreshDatabase;

    protected function defineRoutes($router): void
    {
        $router->middleware('api')->prefix('api')->group(__DIR__.'/../../../routes/api.php');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    private function makeSuperAdmin(): User
    {
        $user = User::create([
            'name' => 'Super',
            'email' => 'super-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);
        $user->assignRole('super-admin');

        return $user;
    }

    private function makeInstallation(string $tenantId = 'default'): ConnectorInstallation
    {
        $user = User::create([
            'name' => 'Tester',
            'email' => 'u-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);

        return ConnectorInstallation::create([
            'tenant_id' => $tenantId,
            'connector_name' => 'evernote',
            'status' => ConnectorInstallation::STATUS_PENDING,
            'created_by' => $user->id,
        ]);
    }

    private function writeEnexFixture(string $body, string $name = 'export.enex'): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'enex-'.uniqid().'-'.$name;
        file_put_contents($path, $body);

        return $path;
    }

    private function threeNoteEnex(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE en-export SYSTEM "http://xml.evernote.com/pub/evernote-export4.dtd">
<en-export export-date="20260511T100000Z" application="Evernote" version="10.0.0">
  <note>
    <title>First note</title>
    <content><![CDATA[<?xml version="1.0" encoding="UTF-8"?><en-note><h1>One</h1><p>body one</p></en-note>]]></content>
    <created>20260101T100000Z</created>
    <updated>20260101T110000Z</updated>
    <tag>project</tag>
    <tag>urgent</tag>
    <note-attributes>
      <source-url>https://example.test/source</source-url>
    </note-attributes>
  </note>
  <note>
    <title>Second note</title>
    <content><![CDATA[<en-note><p>body two</p></en-note>]]></content>
    <created>20260102T100000Z</created>
    <updated>20260102T110000Z</updated>
  </note>
  <note>
    <title>Third</title>
    <content><![CDATA[<en-note><ul><li>a</li><li>b</li></ul></en-note>]]></content>
  </note>
</en-export>
XML;
    }

    public function test_imports_three_notes_and_dispatches_one_job_per_note(): void
    {
        Queue::fake();
        Storage::fake('kb');

        $installation = $this->makeInstallation();
        $file = $this->writeEnexFixture($this->threeNoteEnex());

        $result = $this->app->make(EnexImporter::class)->import(
            $file,
            $installation,
            'connector-evernote',
        );

        $this->assertSame(3, $result->imported);
        $this->assertSame(0, $result->skipped);
        $this->assertSame([], $result->errors);

        Queue::assertPushed(IngestDocumentJob::class, 3);
    }

    public function test_imported_notes_carry_evernote_source_metadata(): void
    {
        Queue::fake();
        Storage::fake('kb');

        $installation = $this->makeInstallation();
        $file = $this->writeEnexFixture($this->threeNoteEnex());

        $this->app->make(EnexImporter::class)->import(
            $file,
            $installation,
            'connector-evernote',
        );

        Queue::assertPushed(IngestDocumentJob::class, function (IngestDocumentJob $job) {
            return ($job->metadata['evernote_source'] ?? null) === 'enex'
                && ($job->metadata['connector'] ?? null) === 'evernote'
                && is_string($job->metadata['evernote_import_id'] ?? null)
                && is_int($job->metadata['evernote_note_index'] ?? null);
        });
    }

    public function test_imported_first_note_includes_tags_and_source_url(): void
    {
        Queue::fake();
        Storage::fake('kb');

        $installation = $this->makeInstallation();
        $file = $this->writeEnexFixture($this->threeNoteEnex());

        $this->app->make(EnexImporter::class)->import(
            $file,
            $installation,
            'connector-evernote',
        );

        Queue::assertPushed(IngestDocumentJob::class, function (IngestDocumentJob $job) {
            $tags = $job->metadata['evernote_tags'] ?? null;
            if (! is_array($tags) || $tags === []) {
                return false;
            }

            return in_array('project', $tags, true)
                && in_array('urgent', $tags, true)
                && ($job->metadata['evernote_source_url'] ?? null) === 'https://example.test/source';
        });
    }

    public function test_imported_markdown_is_written_to_kb_disk_with_title(): void
    {
        Queue::fake();
        Storage::fake('kb');

        $installation = $this->makeInstallation();
        $file = $this->writeEnexFixture($this->threeNoteEnex());

        $this->app->make(EnexImporter::class)->import(
            $file,
            $installation,
            'connector-evernote',
        );

        $disk = Storage::disk((string) config('kb.sources.disk', 'kb'));
        $files = $disk->allFiles();
        $this->assertCount(3, $files);

        // Concatenate all bodies — at least one must contain each
        // expected title heading.
        $bodies = collect($files)->map(static fn ($f) => (string) $disk->get($f))->implode("\n---\n");
        $this->assertStringContainsString('# First note', $bodies);
        $this->assertStringContainsString('# Second note', $bodies);
        $this->assertStringContainsString('# Third', $bodies);
        $this->assertStringContainsString('body one', $bodies);
        $this->assertStringContainsString('body two', $bodies);
    }

    public function test_malformed_xml_throws_invalid_enex_exception(): void
    {
        $installation = $this->makeInstallation();
        $file = $this->writeEnexFixture('<not really xml<<<>>>');

        $this->expectException(InvalidEnexException::class);
        $this->app->make(EnexImporter::class)->import(
            $file,
            $installation,
            'connector-evernote',
        );
    }

    public function test_non_enexport_root_throws_invalid_enex_exception(): void
    {
        $installation = $this->makeInstallation();
        // Valid XML but the root element is not <en-export>.
        $file = $this->writeEnexFixture('<?xml version="1.0"?><foo><bar/></foo>');

        $this->expectException(InvalidEnexException::class);
        $this->app->make(EnexImporter::class)->import(
            $file,
            $installation,
            'connector-evernote',
        );
    }

    public function test_non_enexport_root_with_note_children_does_not_dispatch_jobs(): void
    {
        // R14 + Copilot iter1 finding #3: a non-Evernote XML file that
        // happens to carry <note> elements MUST be rejected BEFORE any
        // file write or job dispatch fires. Validate root-first.
        Queue::fake();
        Storage::fake('kb');

        $installation = $this->makeInstallation();
        $file = $this->writeEnexFixture(
            '<?xml version="1.0"?><foo><note><title>Sneaky</title>'
            .'<content><![CDATA[<en-note><p>should never be written</p></en-note>]]></content>'
            .'</note></foo>'
        );

        $threw = false;
        try {
            $this->app->make(EnexImporter::class)->import(
                $file,
                $installation,
                'connector-evernote',
            );
        } catch (InvalidEnexException) {
            $threw = true;
        }

        $this->assertTrue($threw, 'Expected InvalidEnexException on non-en-export root.');
        Queue::assertNotPushed(IngestDocumentJob::class);
        $disk = Storage::disk((string) config('kb.sources.disk', 'kb'));
        $this->assertSame([], $disk->allFiles(), 'No files should have been written before the root-check rejected.');
    }

    public function test_missing_file_throws_invalid_enex_exception(): void
    {
        $installation = $this->makeInstallation();

        $this->expectException(InvalidEnexException::class);
        $this->app->make(EnexImporter::class)->import(
            '/tmp/this-file-does-not-exist-'.uniqid(),
            $installation,
            'connector-evernote',
        );
    }

    public function test_http_endpoint_returns_202_with_counts_and_dispatches_jobs(): void
    {
        Queue::fake();
        Storage::fake('kb');

        $admin = $this->makeSuperAdmin();

        $payload = $this->threeNoteEnex();
        $tmpPath = $this->writeEnexFixture($payload);
        $uploaded = new UploadedFile(
            $tmpPath,
            'export.enex',
            'application/xml',
            null,
            test: true,
        );

        $response = $this->actingAs($admin)->post(
            '/api/admin/connectors/evernote/import-enex',
            [
                'enex' => $uploaded,
                'project_key' => 'demo',
            ],
        );

        $response->assertStatus(202);
        $response->assertJsonStructure([
            'data' => ['imported', 'skipped', 'errors', 'installation_id', 'project_key'],
        ]);
        $this->assertSame(3, $response->json('data.imported'));

        Queue::assertPushed(IngestDocumentJob::class, 3);
    }

    public function test_http_endpoint_returns_422_for_malformed_enex(): void
    {
        Storage::fake('kb');

        $admin = $this->makeSuperAdmin();

        $tmpPath = $this->writeEnexFixture('<?xml version="1.0"?><foo></foo>');
        $uploaded = new UploadedFile(
            $tmpPath,
            'broken.enex',
            'application/xml',
            null,
            test: true,
        );

        $response = $this->actingAs($admin)->post(
            '/api/admin/connectors/evernote/import-enex',
            ['enex' => $uploaded],
        );

        $response->assertStatus(422);
        $response->assertJson(['code' => 'invalid_enex']);
    }
}

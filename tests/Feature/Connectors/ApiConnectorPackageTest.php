<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\ApiConnectors\ConnectorConfigTransferService;
use App\ApiConnectors\ConnectorPackageService;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRoute;
use Padosoft\AskMyDocsConnectorApi\Models\ApiConnector;
use App\Support\TenantContext;
use Padosoft\AskMyDocsConnectorApi\Services\ApiToolExecutor;
use Tests\TestCase;
use ZipArchive;

final class ApiConnectorPackageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $user = User::create(['name' => 'Admin', 'email' => 'packages@example.com', 'password' => bcrypt('password')]);
        $user->assignRole('admin');
        $this->actingAs($user);
        Cache::flush();
        config()->set('connector-api.ssrf.resolve_dns', false);
        Http::preventStrayRequests();
    }

    public function test_documented_example_imports_from_json_and_zip_and_round_trips_without_network_calls(): void
    {
        $config = $this->example();
        $zip = app(ConnectorPackageService::class)->zip($config);
        $preview = $this->upload('/import/validate', $zip)->assertOk();
        $this->assertSame(2, $preview->json('data.endpoint_count'));
        $this->assertSame('token', $preview->json('data.required_credentials.0.key'));
        $this->assertDatabaseCount('api_connectors', 0);
        $import = $this->upload('/import', $zip, ['credentials' => ['auth-orders' => ['token' => 'local-only-secret']]])->assertCreated();
        $id = $import->json('data.id');
        $json = $this->getJson('/api/admin/api-connectors/'.$id.'/export')->assertOk()->json('data');
        $response = $this->get('/api/admin/api-connectors/'.$id.'/export?format=zip')->assertOk()
            ->assertHeader('Content-Type', 'application/zip');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('local-only-secret', $response->getContent());
        $decoded = app(ConnectorPackageService::class)->read(UploadedFile::fake()->createWithContent('connector.zip', $response->getContent()));
        $this->assertSame($json, $decoded);
        $copy = $this->upload('/import', json_encode($decoded), [
            'credentials' => [$decoded['default_auth_profile_ref'] => ['token' => 'another-local-secret']],
        ], 'connector.json')->assertCreated();
        $connector = ApiConnector::findOrFail($copy->json('data.id'));
        $this->assertSame('Orders API example (2)', $connector->name);
        $this->assertCount(2, $connector->routes);
        $this->assertCount(1, $connector->relations);
        $this->assertSame('list_orders_2', $connector->routes->first()->slug);
        $this->assertSame('active', $connector->routes->first()->status->value);
        $this->assertSame('another-local-secret', $connector->defaultAuthProfile()->credential('token'));
        $this->assertSame($decoded['routes'][0]['input_schema'], $connector->routes->first()->input_schema);
        $this->assertSame($decoded['routes'][0]['output_schema'], $connector->routes->first()->output_schema);
        Http::assertNothingSent();
    }

    public function test_zip_with_optional_readme_is_inert_and_import_does_not_require_a_preview_token(): void
    {
        $zip = $this->archive(['connector.json' => json_encode($this->example()), 'README.md' => '<script>throw new Error("never run")</script>']);
        $this->upload('/import', $zip, ['credentials' => ['auth-orders' => ['token' => 'local-token']]])->assertCreated();
        Http::assertNothingSent();
    }

    public function test_example_routes_execute_with_imported_authentication_and_path_and_query_arguments(): void
    {
        $connector = app(ConnectorConfigTransferService::class)->import($this->example(), ['auth-orders' => ['token' => 'local-token']]);
        Http::fake(['api.example.com/*' => Http::response(['id' => 42, 'status' => 'shipped'])]);
        $executor = app(ApiToolExecutor::class);
        $list = $executor->execute($connector->routes->first()->load('parameters', 'connector'), ['status' => 'shipped']);
        $detail = $executor->execute($connector->routes->last()->load('parameters', 'connector'), ['id' => 42]);
        $this->assertArrayNotHasKey('error', $list, json_encode($list));
        $this->assertArrayNotHasKey('error', $detail, json_encode($detail));
        Http::assertSent(fn ($request) => $request->url() === 'https://api.example.com/v1/orders?status=shipped'
            && $request->hasHeader('Authorization', 'Bearer local-token'));
        Http::assertSent(fn ($request) => $request->url() === 'https://api.example.com/v1/orders/42'
            && $request->hasHeader('Authorization', 'Bearer local-token'));
    }

    public function test_zip_uses_the_existing_permission_and_tenant_boundaries(): void
    {
        $connector = app(ConnectorConfigTransferService::class)->import($this->example(), ['auth-orders' => ['token' => 'local-token']]);
        $zip = app(ConnectorPackageService::class)->zip($this->example());
        $viewer = User::create(['name' => 'Viewer', 'email' => 'viewer@example.com', 'password' => bcrypt('password')]);
        $viewer->assignRole('viewer');
        $this->actingAs($viewer);
        $this->upload('/import/validate', $zip)->assertForbidden();
        $this->upload('/import', $zip)->assertForbidden();
        $this->getJson('/api/admin/api-connectors/'.$connector->id.'/export?format=zip')->assertForbidden();
        $admin = User::where('email', 'packages@example.com')->firstOrFail();
        app(TenantContext::class)->set('package-destination');
        app(\Padosoft\AskMyDocsConnectorBase\Support\TenantContext::class)->set('package-destination');
        $this->actingAs($admin);
        $this->getJson('/api/admin/api-connectors/'.$connector->id.'/export?format=zip')->assertNotFound();
        $copy = $this->upload('/import', $zip, ['credentials' => ['auth-orders' => ['token' => 'destination-token']]])->assertCreated();
        $imported = ApiConnector::findOrFail($copy->json('data.id'));
        $this->assertSame('package-destination', $imported->tenant_id);
        $this->assertSame('package-destination', $imported->routes->first()->tenant_id);
        $this->assertSame('package-destination', $imported->authProfiles->first()->tenant_id);
        $this->assertSame('package-destination', $imported->relations->first()->tenant_id);
        auth()->forgetGuards();
        $this->upload('/import', $zip)->assertUnauthorized();
    }

    public function test_unsafe_and_corrupt_archives_are_rejected_without_creating_rows(): void
    {
        $json = json_encode($this->example());
        $cases = [
            'not a zip',
            $this->archive(['README.md' => 'missing configuration']),
            $this->archive(['nested/connector.json' => $json]),
            $this->archive(['../connector.json' => $json]),
            $this->archive(['/connector.json' => $json]),
            $this->archive(['C:\\connector.json' => $json]),
            $this->archive(['connector.json' => $json, 'run.php' => '<?php exit;']),
            $this->archive(['connector.json' => $json, 'README.md' => 'ok', 'extra.txt' => 'no']),
            $this->archive(['connector.json' => str_repeat(' ', ConnectorPackageService::MAX_BYTES + 1)]),
            $this->archive(['connector.json' => $json, 'README.md' => str_repeat('a', ConnectorPackageService::MAX_README_BYTES + 1)]),
            $this->archive(['connector.json' => str_repeat('a', 100000)]), // compressed bomb ratio
            $this->archive(['connector.json' => $json], symlink: true),
            $this->archive(['connector.json' => $json], encrypted: true),
            str_replace('Orders API example', 'Orders XXX example', $this->archive(['connector.json' => $json], stored: true)),
            str_replace('xonnector.json', 'connector.json', $this->archive(['connector.json' => $json, 'xonnector.json' => $json])),
        ];
        foreach ($cases as $bytes) {
            $this->upload('/import', $bytes)->assertUnprocessable();
            $this->assertDatabaseCount('api_connectors', 0);
            $this->assertDatabaseCount('api_auth_profiles', 0);
            $this->assertDatabaseCount('api_routes', 0);
        }
    }

    public function test_invalid_json_shapes_versions_and_runtime_configuration_fail_with_clear_errors(): void
    {
        foreach (['not json', '[]', 'null', '{"bad":', '{"a":'.str_repeat('[', 65).'0'.str_repeat(']', 65).'}'] as $json) {
            $this->upload('/import', $json, [], 'connector.json')->assertUnprocessable()->assertJsonValidationErrors('file');
        }
        foreach ([
            ['_meta.version', 999], ['_meta.format', 'openapi'], ['routes', ['bad-key' => []]],
            ['auth_profiles.0.config.client_secret', 'inline-secret'],
            ['connector.headers.X-Private', 'inline-secret'],
            ['connector.base_url', 'file:///etc/passwd'],
            ['routes.0.url', 'https://user:password@example.com/orders'],
            ['routes.0.url', 'https://api.example.com/orders?api_key=inline-secret'],
            ['routes.0.url', 'https://api.example.com/orders/{missing}'],
            ['routes.0.parameters.0.source', 'code'],
            ['routes.0.output_transform', ['script' => 'process.exit()']],
            ['routes.0.input_schema', ['not-an-object']],
            ['routes.0.tool_definition.description', ['unexpected array']],
            ['routes.0.parameters.0.location', 'body'],
            ['relations.0.field_map.0.to_location', 'query'],
            ['routes.0.parameters.1', $this->example()['routes'][0]['parameters'][0]],
            ['auth_profiles.0.ref', 'auth-with.dot'],
            ['auth_profiles.0.secret_fields', ['headers']],
            ['routes.0.parameters.0', [...$this->example()['routes'][0]['parameters'][0], 'source' => 'secret', 'secret_ref' => 'headers']],
            ['routes.0.tool_definition.input_schema', ['not-an-object']],
            ['connector.headers.Accept', "application/json\r\nX-Injected: true"],
        ] as [$field, $value]) {
            $config = $this->example();
            data_set($config, $field, $value);
            $this->postJson('/api/admin/api-connectors/import', ['config' => $config])->assertUnprocessable();
            $this->assertDatabaseCount('api_connectors', 0);
        }
        $invalid = $this->example();
        $invalid['_meta']['version'] = 999;
        $this->postJson('/api/admin/api-connectors/import/validate', ['config' => $invalid])->assertUnprocessable()
            ->assertJsonValidationErrors('_meta.version')->assertJsonPath('message', 'Unsupported API connector configuration version. Supported version: 1.');
    }

    public function test_file_size_extension_and_ambiguous_payload_are_rejected(): void
    {
        $this->upload('/import', str_repeat('x', ConnectorPackageService::MAX_BYTES + 1), [], 'connector.json')->assertUnprocessable();
        $this->upload('/import', '{}', [], 'connector.php')->assertUnprocessable();
        $this->upload('/import', json_encode($this->example()), ['config' => $this->example()], 'connector.json')->assertUnprocessable();
        $this->assertDatabaseCount('api_connectors', 0);
    }

    public function test_a_mid_import_failure_rolls_back_the_uploaded_package_and_returns_a_safe_message(): void
    {
        $count = 0;
        Event::listen('eloquent.creating: '.ApiRoute::class, function () use (&$count): void {
            if (++$count === 2) throw new \RuntimeException('Internal diagnostic with a private value.');
        });
        $response = $this->upload('/import', app(ConnectorPackageService::class)->zip($this->example()),
            ['credentials' => ['auth-orders' => ['token' => 'local-token']]])->assertStatus(500);
        $this->assertStringContainsString('No connector was imported', $response->json('message'));
        $this->assertStringNotContainsString('private value', $response->getContent());
        foreach (['api_connectors', 'api_auth_profiles', 'api_routes', 'api_route_parameters', 'api_route_relations'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_export_removes_custom_headers_and_fixed_auth_parameters_and_rebuilds_their_mapping(): void
    {
        $connector = app(ConnectorConfigTransferService::class)->import($this->example(), ['auth-orders' => ['token' => 'local-token']]);
        $connector->update(['headers' => ['X-Organization' => 'private-organization', 'User-Agent' => 'AskMyDocs']]);
        $connector->routes->first()->parameters()->create(['tenant_id' => $connector->tenant_id, 'name' => 'api_key',
            'source' => 'fixed', 'location' => 'query', 'type' => 'string', 'value' => 'fixed-private-key']);
        $config = app(ConnectorConfigTransferService::class)->export($connector->id);
        $serialized = json_encode($config);
        foreach (['private-organization', 'fixed-private-key', 'local-token'] as $secret) $this->assertStringNotContainsString($secret, $serialized);
        $this->assertSame(['X-Organization'], $config['connector']['secret_headers']);
        $parameter = collect($config['routes'][0]['parameters'])->firstWhere('name', 'api_key');
        $this->assertSame('secret', $parameter['source']);
        $this->assertNull($parameter['value']);
        $this->assertSame('secret', $config['routes'][0]['param_mapping']['api_key']['source']);
        $fields = app(ConnectorConfigTransferService::class)->preview($config)['required_credentials'];
        $this->assertCount(3, $fields);
    }

    public function test_export_refuses_a_known_secret_copied_into_public_prose_or_a_url(): void
    {
        $connector = app(ConnectorConfigTransferService::class)->import($this->example(), ['auth-orders' => ['token' => 'very-private-token']]);
        $connector->update(['description' => 'Accidental copy: very-private-token']);
        $response = $this->getJson('/api/admin/api-connectors/'.$connector->id.'/export?format=zip')->assertUnprocessable();
        $this->assertStringNotContainsString('very-private-token', $response->getContent());
    }

    public function test_secret_parameter_values_are_not_echoed_in_a_preview(): void
    {
        $config = $this->example();
        $config['routes'][0]['parameters'][0] = [...$config['routes'][0]['parameters'][0], 'source' => 'secret', 'secret_ref' => 'signature', 'value' => 'accidental-private-value'];
        $response = $this->postJson('/api/admin/api-connectors/import/validate', ['config' => $config])->assertOk();
        $this->assertStringNotContainsString('accidental-private-value', $response->getContent());
        $this->assertNull($response->json('data.config.routes.0.parameters.0.value'));
    }

    private function example(): array
    {
        return json_decode(file_get_contents(dirname(__DIR__, 3).'/docs/connectors/api-packages/v1/connector.example.json'), true, 64, JSON_THROW_ON_ERROR);
    }

    private function upload(string $suffix, string $bytes, array $data = [], string $filename = 'connector.zip'): \Illuminate\Testing\TestResponse
    {
        return $this->withHeader('Accept', 'application/json')->post('/api/admin/api-connectors'.$suffix,
            [...$data, 'file' => UploadedFile::fake()->createWithContent($filename, $bytes)]);
    }

    private function archive(array $entries, bool $symlink = false, bool $encrypted = false, bool $stored = false): string
    {
        $path = tempnam(sys_get_temp_dir(), 'amd-api-test-');
        try {
            $zip = new ZipArchive;
            $zip->open($path, ZipArchive::OVERWRITE);
            foreach ($entries as $name => $content) {
                $zip->addFromString($name, $content);
                if ($stored) $zip->setCompressionName($name, ZipArchive::CM_STORE);
            }
            if ($symlink) $zip->setExternalAttributesName('connector.json', ZipArchive::OPSYS_UNIX, 0120777 << 16);
            if ($encrypted) $zip->setEncryptionName('connector.json', ZipArchive::EM_AES_256, 'password');
            $zip->close();
            return file_get_contents($path);
        } finally {
            unlink($path);
        }
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\ApiConnectors\ConnectorConfigTransferService;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRoute;
use Padosoft\AskMyDocsConnectorApi\Services\ApiRouteTester;
use Padosoft\AskMyDocsConnectorApi\Services\ApiToolExecutor;
use Tests\TestCase;

final class ApiConnectorUserAgentTest extends TestCase
{
    use RefreshDatabase;

    public function test_saved_user_agent_is_used_in_tests_and_runtime_and_survives_export(): void
    {
        $this->seed(RbacSeeder::class);
        $user = User::create([
            'name' => 'API administrator',
            'email' => 'api-user-agent@example.test',
            'password' => bcrypt('test-password'),
            'email_verified_at' => now(),
        ]);
        $user->assignRole('super-admin');
        $this->actingAs($user);
        config()->set('connector-api.ssrf.resolve_dns', false);
        Http::preventStrayRequests();
        Http::fake(['api.example.com/*' => Http::response(['ok' => true])]);

        $created = $this->postJson('/api/admin/api-connectors', [
            'name' => 'Orders',
            'headers' => ['User-Agent' => 'AskMyDocs/1.0', 'X-Account' => 'orders'],
        ])->assertCreated()->assertJsonPath('data.headers.User-Agent', 'AskMyDocs/1.0');
        $connectorId = $created->json('data.id');
        $userAgent = 'Mozilla/5.0 PartnerBrowser/2.0';

        $this->patchJson('/api/admin/api-connectors/'.$connectorId, [
            'headers' => ['User-Agent' => $userAgent, 'X-Account' => 'orders'],
        ])->assertOk();
        $this->getJson('/api/admin/api-connectors/'.$connectorId)->assertOk()
            ->assertJsonPath('data.headers.User-Agent', $userAgent);

        $route = ApiRoute::create([
            'api_connector_id' => $connectorId,
            'project_key' => '',
            'name' => 'Orders',
            'slug' => 'get_orders',
            'http_method' => 'GET',
            'url' => 'https://api.example.com/orders',
            'mode' => 'tool',
            'status' => 'active',
        ])->load('parameters', 'connector');

        $this->assertTrue(app(ApiRouteTester::class)->test($route)->ok);
        $this->assertSame(['ok' => true], app(ApiToolExecutor::class)->execute($route, []));
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->hasHeader('User-Agent', $userAgent)
            && $request->hasHeader('X-Account', 'orders'));
        foreach (Http::recorded() as [$request]) {
            $this->assertSame([$userAgent], $request->header('User-Agent'));
        }

        $transfer = app(ConnectorConfigTransferService::class);
        $export = $transfer->export($connectorId);
        $this->assertSame($userAgent, $export['connector']['headers']['User-Agent']);
        $imported = $transfer->import($export, []);
        $this->assertSame($userAgent, $imported->headers['User-Agent']);
    }
}

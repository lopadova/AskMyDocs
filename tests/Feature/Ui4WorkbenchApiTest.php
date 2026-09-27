<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ProjectMembership;
use App\Models\Ui4WorkbenchSandboxObject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class Ui4WorkbenchApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RbacSeeder::class);
    }

    public function test_it_requires_an_authenticated_tenant_session(): void
    {
        $this->getJson('/api/workbench/snapshot')->assertUnauthorized();
    }

    public function test_it_persists_sandbox_objects_proposals_relations_and_layouts_through_the_package_api(): void
    {
        $user = $this->workbenchUser('workbench-owner@example.test');
        $this->grantWorkbenchMembership($user, 'acme');

        $noteId = $this->createWorkbenchObject($user, 'acme', 'owner-note', 'Private note');
        $contactId = $this->createWorkbenchObject($user, 'acme', 'owner-contact', 'Ada Lovelace', 'contact');
        $appointmentId = $this->actingAs($user)
            ->withHeaders(['X-Tenant-Id' => 'acme'])
            ->postJson('/api/workbench/invoke', [
                'type' => 'appointment',
                'action' => 'record.create',
                'input' => [
                    'title' => 'Review with Ada',
                    'data' => [
                        'starts_at' => '2026-09-25T09:00:00Z',
                        'ends_at' => '2026-09-25T09:30:00Z',
                    ],
                ],
                'idempotency_key' => 'owner-appointment',
            ])
            ->assertOk()
            ->json('operation.result.records.0.id');

        $this->assertDatabaseHas('ui4_workbench_sandbox_objects', [
            'tenant_id' => 'acme',
            'user_id' => $user->id,
            'type' => 'note',
            'title' => 'Private note',
        ]);

        $this->actingAs($user)
            ->withHeaders(['X-Tenant-Id' => 'acme'])
            ->postJson('/api/workbench/invoke', [
                'type' => 'appointment',
                'action' => 'relation.link',
                'input' => [
                    'from_id' => $appointmentId,
                    'to_id' => $contactId,
                    'relation' => 'appointment.participant',
                    'attributes' => ['confirmation' => 'pending'],
                ],
                'idempotency_key' => 'owner-relation',
            ])
            ->assertOk()
            ->assertJsonPath('operation.status', 'succeeded');

        $proposal = $this->actingAs($user)
            ->withHeaders(['X-Tenant-Id' => 'acme'])
            ->postJson('/api/workbench/invoke', [
                'type' => 'note',
                'action' => 'record.archive',
                'target_id' => $noteId,
                'input' => [],
                'idempotency_key' => 'owner-archive',
            ]);

        $proposal->assertOk()->assertJsonPath('operation.status', 'proposed');

        $this->actingAs($user)
            ->withHeaders(['X-Tenant-Id' => 'acme'])
            ->postJson('/api/workbench/operations/'.$proposal->json('operation.id').'/confirm')
            ->assertOk()
            ->assertJsonPath('operation.status', 'succeeded');

        $this->assertNotNull(Ui4WorkbenchSandboxObject::query()
            ->where('tenant_id', 'acme')
            ->where('user_id', $user->id)
            ->where('type', 'note')
            ->where('title', 'Private note')
            ->value('archived_at'));

        $this->actingAs($user)
            ->withHeaders(['X-Tenant-Id' => 'acme'])
            ->putJson('/api/workbench/layout', [
                'windows' => [['id' => 'appointment-card', 'recordId' => $appointmentId, 'mode' => 'widget']],
                'focused' => 'appointment-card',
                'version' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('layout.focused', 'appointment-card');

        $this->actingAs($user)
            ->withHeaders(['X-Tenant-Id' => 'acme'])
            ->getJson('/api/workbench/snapshot')
            ->assertOk()
            ->assertJsonCount(2, 'records')
            ->assertJsonPath('layout.focused', 'appointment-card');

        $this->actingAs($user)
            ->withHeaders(['X-Tenant-Id' => 'acme'])
            ->getJson('/api/workbench/records/'.$appointmentId.'/relations')
            ->assertOk()
            ->assertJsonPath('relations.0.relation', 'appointment.participant');
    }

    public function test_it_does_not_disclose_sandbox_records_across_users_or_tenants(): void
    {
        $owner = $this->workbenchUser('workbench-owner-isolation@example.test');
        $colleague = $this->workbenchUser('workbench-colleague-isolation@example.test');
        $this->grantWorkbenchMembership($owner, 'acme');
        $this->grantWorkbenchMembership($owner, 'globex');
        $this->grantWorkbenchMembership($colleague, 'acme');

        $recordId = $this->createWorkbenchObject($owner, 'acme', 'owner-isolated-note', 'Only the owner can read this');

        $this->actingAs($colleague)
            ->withHeaders(['X-Tenant-Id' => 'acme'])
            ->getJson('/api/workbench/records/'.$recordId)
            ->assertNotFound();

        $this->actingAs($owner)
            ->withHeaders(['X-Tenant-Id' => 'globex'])
            ->getJson('/api/workbench/records/'.$recordId)
            ->assertNotFound();

        $this->actingAs($colleague)
            ->withHeaders(['X-Tenant-Id' => 'acme'])
            ->getJson('/api/workbench/snapshot')
            ->assertOk()
            ->assertJsonCount(0, 'records');
    }

    public function test_it_keeps_an_idempotency_key_scoped_to_its_host_identity(): void
    {
        $first = $this->workbenchUser('workbench-first-idempotency@example.test');
        $second = $this->workbenchUser('workbench-second-idempotency@example.test');
        $this->grantWorkbenchMembership($first, 'acme');
        $this->grantWorkbenchMembership($second, 'acme');

        $firstId = $this->createWorkbenchObject($first, 'acme', 'shared-key', 'First actor');
        $secondId = $this->createWorkbenchObject($second, 'acme', 'shared-key', 'Second actor');

        $this->assertNotSame($firstId, $secondId);
        $this->assertSame(2, Ui4WorkbenchSandboxObject::query()->count());
    }

    private function workbenchUser(string $email): User
    {
        return User::create([
            'name' => 'Workbench tester',
            'email' => $email,
            'password' => Hash::make('secret'),
        ]);
    }

    private function grantWorkbenchMembership(User $user, string $tenantId): void
    {
        ProjectMembership::create([
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'project_key' => 'workbench-test',
            'role' => 'member',
            'scope_allowlist' => null,
        ]);
    }

    private function createWorkbenchObject(User $user, string $tenantId, string $key, string $title, string $type = 'note'): string
    {
        $response = $this->actingAs($user)
            ->withHeaders(['X-Tenant-Id' => $tenantId])
            ->postJson('/api/workbench/invoke', [
                'type' => $type,
                'action' => 'record.create',
                'input' => [
                    'title' => $title,
                    'data' => ['body' => 'Isolated local workbench data.'],
                ],
                'idempotency_key' => $key,
            ]);

        $response->assertOk()->assertJsonPath('operation.status', 'succeeded');

        return (string) $response->json('operation.result.records.0.id');
    }
}

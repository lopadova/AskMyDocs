<?php

declare(strict_types=1);

namespace Tests\Unit\Compliance;

use App\Compliance\AskMyDocsUserDataDeleter;
use App\Compliance\AskMyDocsUserDataExporter;
use App\Models\Conversation;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Padosoft\PiiRedactor\TokenStore\Eloquent\PiiTokenMap;
use Tests\TestCase;

/**
 * v8.23 (Ciclo 4) — the DSAR ⇄ token-vault wiring: Art.17 erasure crypto-shreds
 * the subject's vault entries; Art.15 export surfaces them. Both keyed by the
 * user's email, tenant-scoped (R30).
 */
final class DsarVaultWiringTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): User
    {
        return User::create([
            'name' => 'Subject',
            'email' => 'subject-'.uniqid().'@example.test',
            'password' => Hash::make('secret-password'),
        ]);
    }

    private function vault(string $tenant, string $original): void
    {
        PiiTokenMap::create([
            'tenant_id' => $tenant,
            'token' => '[tok:email:'.substr(md5($tenant.$original), 0, 12).']',
            'original' => $original,
            'detector' => 'email',
        ]);
    }

    public function test_dsar_erasure_crypto_shreds_the_subject_vault_in_each_tenant(): void
    {
        $user = $this->makeUser();
        app(TenantContext::class)->set('tenant-a');

        // The user owns data in tenant-a (so the resolver includes it) and has a
        // vault entry there keyed by their email; a foreign tenant's identical
        // mapping must survive.
        Conversation::query()->create([
            'tenant_id' => 'tenant-a',
            'user_id' => $user->id,
            'title' => 'c',
            'project_key' => 'alpha',
        ]);
        $this->vault('tenant-a', $user->email);
        $this->vault('tenant-z', $user->email);

        app(AskMyDocsUserDataDeleter::class)->delete($user);

        $this->assertDatabaseMissing('pii_token_maps', ['tenant_id' => 'tenant-a', 'original' => $user->email]);
        $this->assertDatabaseHas('pii_token_maps', ['tenant_id' => 'tenant-z', 'original' => $user->email]);
    }

    public function test_dsar_export_includes_the_subject_vault_snapshot(): void
    {
        $user = $this->makeUser();
        app(TenantContext::class)->set('tenant-a');

        Conversation::query()->create([
            'tenant_id' => 'tenant-a',
            'user_id' => $user->id,
            'title' => 'c',
            'project_key' => 'alpha',
        ]);
        $this->vault('tenant-a', $user->email);

        $export = app(AskMyDocsUserDataExporter::class)->export($user);

        $this->assertArrayHasKey('pii_vault', $export);
        $originals = array_column($export['pii_vault'], 'original');
        $this->assertContains($user->email, $originals);
    }
}

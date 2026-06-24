<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Support\TenantContext;
use Padosoft\AskMyDocsConnectorBase\Auth\OAuthCredentialVault;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext as PackageTenantContext;

/**
 * Seeds the test IMAP connector installations — one per mailbox in
 * {@see TestEmailFixtures} — already wired to the CORRECT folder per casella
 * (`config_json.folders.include = [<Gmail label>]`) so each company's chat only
 * ever sees its own labelled mail.
 *
 * Why a seeder AND a command: `connector:imap:install` does a REAL IMAP ping to
 * verify credentials before going ACTIVE — great for live runs, but it needs the
 * network. This seeder is the OFFLINE path: it writes the rows + folders directly
 * and, when the shared App Password is present in `.env`
 * (`CONNECTOR_TEST_GMAIL_PASSWORD`), vaults it and marks the row ACTIVE so a sync
 * works immediately; without the password it leaves the row PENDING (configured
 * but unauthenticated) — never a half-broken silent state (R14).
 *
 * One tenant per company (R30/R31): every row + credential is created in the
 * mailbox's company tenant (tenant_id = project_key), so the connectors panel
 * isolates them. Idempotent: the (tenant, imap, label) row is deleted + recreated
 * (the FK cascades the old credential). **Run AFTER {@see CaseStudyUsersSeeder}**
 * (it needs a user for `created_by` and the company tenants/projects to exist):
 *
 *   php artisan db:seed --class=Database\\Seeders\\CaseStudyUsersSeeder
 *   php artisan db:seed --class=Database\\Seeders\\ConnectorInstallationsSeeder
 */
class ConnectorInstallationsSeeder extends \Illuminate\Database\Seeder
{
    private const CONNECTOR = 'imap';

    public function run(): void
    {
        $actorId = User::query()->orderBy('id')->value('id');
        if ($actorId === null) {
            $this->command?->warn('ConnectorInstallationsSeeder: nessun utente nel DB — esegui prima CaseStudyUsersSeeder. Salto.');

            return;
        }

        $host = app(TenantContext::class);
        $packageHost = app(PackageTenantContext::class);
        $vault = app(OAuthCredentialVault::class);

        $previous = $host->current();
        $previousPackage = $packageHost->current();

        try {
            foreach (TestEmailFixtures::mailboxKeys() as $mailboxKey) {
                $this->seedMailbox($mailboxKey, (int) $actorId, $host, $packageHost, $vault);
            }
        } finally {
            $host->set($previous);
            $packageHost->set($previousPackage);
        }
    }

    private function seedMailbox(
        string $mailboxKey,
        int $actorId,
        TenantContext $host,
        PackageTenantContext $packageHost,
        OAuthCredentialVault $vault,
    ): void {
        $mailbox = TestEmailFixtures::mailbox($mailboxKey);
        $tenantId = TestEmailFixtures::tenantFor($mailboxKey);
        $projectKey = (string) $mailbox['project_key'];

        // Both contexts: the model + vault read the PACKAGE context, the host code
        // reads the host one (the package singleton is a write-once snapshot — see
        // tenant-context-package-snapshot-gotcha).
        $host->set($tenantId);
        $packageHost->set($tenantId);

        $config = TestEmailFixtures::configJson($mailboxKey);
        // project_key is a v8.20 COLUMN, not a config_json key — drop the legacy
        // nested copy so the stored shape matches what the install command writes.
        unset($config['project_key']);

        // Idempotent: remove any prior row for this label (FK cascades its secret).
        ConnectorInstallation::query()
            ->where('tenant_id', $tenantId)
            ->where('connector_name', self::CONNECTOR)
            ->where('label', $mailboxKey)
            ->delete();

        $password = $this->passwordOrNull($mailboxKey);

        $installation = ConnectorInstallation::create([
            'tenant_id' => $tenantId,
            'connector_name' => self::CONNECTOR,
            'label' => $mailboxKey,
            'project_key' => $projectKey,
            'config_json' => $config,
            'status' => $password === null
                ? ConnectorInstallation::STATUS_PENDING
                : ConnectorInstallation::STATUS_ACTIVE,
            'created_by' => $actorId,
        ]);

        if ($password !== null) {
            // Offline activation: vault the shared App Password (no live ping).
            $vault->setCredentials($installation->id, $password);
        }

        $folders = implode(', ', (array) ($config['folders']['include'] ?? []));
        $this->command?->line(sprintf(
            '  [%s] tenant %s · project %s · folders[%s] · %s',
            $mailboxKey,
            $tenantId,
            $projectKey,
            $folders,
            $installation->status,
        ));
    }

    private function passwordOrNull(string $mailboxKey): ?string
    {
        try {
            return TestEmailFixtures::passwordFor($mailboxKey);
        } catch (\RuntimeException) {
            // No App Password in .env → leave the row PENDING (configured, not
            // authenticated). The folders are still set correctly.
            return null;
        }
    }
}

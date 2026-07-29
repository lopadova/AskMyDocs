<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Invitations\RegistrationInvitationIssuer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

final class CreateRegistrationInviteCommand extends Command
{
    protected $signature = 'registration-invite:create
        {--tenant= : Target operational tenant; omit to require company onboarding}
        {--project=* : Target project key(s), required with --tenant}
        {--role=viewer : Global account role for a tenant-linked invitation}
        {--membership-role=member : Project membership role: member, admin, or owner}
        {--uses=1 : Maximum number of redemptions}
        {--expires= : Expiration date/time parseable by Carbon}
        {--issuer= : Optional issuer user id or email for audit attribution}';

    protected $description = 'Create a public registration invite, with optional tenant provisioning.';

    public function handle(RegistrationInvitationIssuer $issuer): int
    {
        $owner = $this->resolveIssuer();
        if ($this->option('issuer') !== null && $owner === null) {
            return self::FAILURE;
        }

        $expiresAt = null;
        if (trim((string) $this->option('expires')) !== '') {
            try {
                $expiresAt = CarbonImmutable::parse((string) $this->option('expires'));
            } catch (\Throwable) {
                $this->error('The --expires value is not a valid date/time.');

                return self::FAILURE;
            }
        }

        try {
            $tenant = trim((string) $this->option('tenant'));
            $code = $tenant === ''
                ? $issuer->issueCompanyBootstrap(
                    (int) $this->option('uses'),
                    $expiresAt,
                    $owner,
                )
                : $issuer->issueTenantJoin(
                    $tenant,
                    (array) $this->option('project'),
                    (string) $this->option('role'),
                    (string) $this->option('membership-role'),
                    (int) $this->option('uses'),
                    $expiresAt,
                    $owner,
                );
        } catch (ValidationException $e) {
            foreach ($e->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }

            return self::FAILURE;
        }

        $metadata = is_array($code->metadata) ? $code->metadata : [];
        $this->info('Registration invite created.');
        $this->table(['Field', 'Value'], [
            ['Code', $code->code],
            ['Intent', (string) ($metadata['registration_intent'] ?? '')],
            ['Target tenant', (string) ($metadata['target_tenant'] ?? 'created during onboarding')],
            ['Maximum uses', (string) $code->max_uses],
            ['Expires', $code->expires_at?->toIso8601String() ?? 'never'],
        ]);

        return self::SUCCESS;
    }

    private function resolveIssuer(): ?User
    {
        $reference = trim((string) $this->option('issuer'));
        if ($reference === '') {
            return null;
        }

        $user = ctype_digit($reference)
            ? User::query()->find((int) $reference)
            : User::query()->where('email_normalized', User::normalizeEmail($reference))->first();
        if ($user === null) {
            $this->error("Issuer not found: {$reference}");
        }

        return $user;
    }
}

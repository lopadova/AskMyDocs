<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Models\ProjectMembership;
use App\Models\TenantBranding;
use App\Models\User;
use App\Support\SystemTenantRegistry;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class TenantBrandingService
{
    /** @return array{logo_url: string, mime_type: string, original_name: ?string} */
    public function store(User $actor, string $tenantId, UploadedFile $logo): array
    {
        $tenantId = $this->authorizeTenant($actor, $tenantId);
        $disk = $this->disk();
        $extension = strtolower($logo->guessExtension() ?: $logo->extension() ?: 'bin');
        $newPath = $tenantId.'/'.Str::uuid().'.'.$extension;

        $stored = Storage::disk($disk)->putFileAs($tenantId, $logo, basename($newPath));
        if ($stored === false) {
            throw new \RuntimeException('The tenant logo could not be stored.');
        }

        $previousPath = null;
        try {
            DB::transaction(function () use ($tenantId, $newPath, $logo, &$previousPath): void {
                $branding = TenantBranding::query()->forTenant($tenantId)->lockForUpdate()->first();
                $previousPath = $branding?->logo_path;

                TenantBranding::query()->updateOrCreate(
                    ['tenant_id' => $tenantId],
                    [
                        'logo_path' => $newPath,
                        'mime_type' => (string) ($logo->getMimeType() ?: 'application/octet-stream'),
                        'original_name' => $logo->getClientOriginalName(),
                    ],
                );
            });
        } catch (\Throwable $e) {
            Storage::disk($disk)->delete($newPath);
            throw $e;
        }

        if (is_string($previousPath) && $previousPath !== $newPath) {
            Storage::disk($disk)->delete($previousPath);
        }

        return $this->payload($tenantId) ?? throw new \RuntimeException('The tenant logo could not be loaded after storing it.');
    }

    public function delete(User $actor, string $tenantId): void
    {
        $tenantId = $this->authorizeTenant($actor, $tenantId);
        $branding = TenantBranding::query()->forTenant($tenantId)->first();
        if ($branding === null) {
            return;
        }

        $path = $branding->logo_path;
        $branding->delete();
        Storage::disk($this->disk())->delete($path);
    }

    public function forViewer(User $viewer, string $tenantId): ?TenantBranding
    {
        $tenantId = $this->authorizeTenant($viewer, $tenantId, allowReserved: false);

        if (! Schema::hasTable('tenant_brandings')) {
            return null;
        }

        return TenantBranding::query()->forTenant($tenantId)->first();
    }

    /** @return array{logo_url: string, mime_type: string, original_name: ?string}|null */
    public function payload(string $tenantId): ?array
    {
        if (! Schema::hasTable('tenant_brandings')) {
            return null;
        }

        $branding = TenantBranding::query()->forTenant($tenantId)->first();
        if ($branding === null) {
            return null;
        }

        return [
            'logo_url' => route('api.tenant-logos.show', ['slug' => $tenantId], false),
            'mime_type' => (string) $branding->mime_type,
            'original_name' => $branding->original_name,
        ];
    }

    /** @param list<string> $tenantIds @return array<string, string> */
    public function logoUrls(array $tenantIds): array
    {
        if ($tenantIds === [] || ! Schema::hasTable('tenant_brandings')) {
            return [];
        }

        return TenantBranding::query()
            ->whereIn('tenant_id', $tenantIds)
            ->pluck('tenant_id')
            ->mapWithKeys(fn (string $tenantId): array => [
                $tenantId => route('api.tenant-logos.show', ['slug' => $tenantId], false),
            ])
            ->all();
    }

    public function disk(): string
    {
        return (string) config('tenant-branding.disk', 'tenant-logos');
    }

    private function authorizeTenant(User $user, string $tenantId, bool $allowReserved = false): string
    {
        $tenantId = Str::lower(trim($tenantId));
        if ($tenantId === '' || (! $allowReserved && SystemTenantRegistry::isReserved($tenantId))) {
            throw new NotFoundHttpException('Team not found.');
        }

        $member = ProjectMembership::query()
            ->forTenant($tenantId)
            ->where('user_id', $user->id)
            ->exists();
        if (! $member) {
            throw new NotFoundHttpException('Team not found.');
        }

        return $tenantId;
    }
}

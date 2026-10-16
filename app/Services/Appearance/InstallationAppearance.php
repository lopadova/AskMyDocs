<?php

declare(strict_types=1);

namespace App\Services\Appearance;

use App\Models\InstallationAppearance as AppearanceRecord;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** One global theme, shared by guest screens and every authenticated tenant. */
final class InstallationAppearance
{
    public const DEFAULT_THEME = 'humanistic';

    public const THEMES = ['humanistic', 'tech-pro', 'retro-future', 'dynamic-office', 'luxury'];

    public function theme(): string
    {
        try {
            // Keep guest/auth shells usable while the additive migration is pending.
            if (! Schema::hasTable('installation_appearance')) {
                return self::DEFAULT_THEME;
            }

            $theme = AppearanceRecord::query()->whereKey(1)->value('theme');
        } catch (QueryException $error) {
            // A cosmetic setting must not take the public landing offline on a DB outage.
            report($error);

            return self::DEFAULT_THEME;
        }

        // Never allow a stale/corrupt record to inject a stylesheet or root attribute.
        return in_array($theme, self::THEMES, true) ? $theme : self::DEFAULT_THEME;
    }

    public function setTheme(string $theme, User $actor): string
    {
        abort_unless($actor->hasRole('super-admin'), 403);

        if (! in_array($theme, self::THEMES, true)) {
            throw ValidationException::withMessages(['theme' => ['The selected theme is invalid.']]);
        }

        abort_unless(
            Schema::hasTable('installation_appearance'),
            503,
            'Installation appearance storage is not available.',
        );

        AppearanceRecord::query()->updateOrCreate(['id' => 1], [
            'theme' => $theme,
            'updated_by' => $actor->getKey(),
        ]);

        return $theme;
    }
}

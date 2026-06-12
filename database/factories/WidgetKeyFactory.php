<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\WidgetKey;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory per WidgetKey — chiave API del widget KITT.
 *
 * @extends Factory<WidgetKey>
 */
final class WidgetKeyFactory extends Factory
{
    protected $model = WidgetKey::class;

    public function definition(): array
    {
        return [
            'tenant_id' => 'default',
            'project_key' => 'docs-v3',
            'public_key' => 'pk_' . \Illuminate\Support\Str::random(24),
            'allowed_origins' => ['https://allowed.test'],
            'rate_limit' => 1000,
            'skill' => 'askmydocs-assistant@1',
            'is_active' => true,
            'label' => 'test-key',
        ];
    }
}
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\WidgetKey;
use App\Models\WidgetSession;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Factory per WidgetSession — sessione conversazionale KITT.
 *
 * @extends Factory<WidgetSession>
 */
final class WidgetSessionFactory extends Factory
{
    protected $model = WidgetSession::class;

    public function definition(): array
    {
        return [
            'tenant_id' => 'default',
            'widget_key_id' => WidgetKey::factory(),
            'project_key' => 'docs-v3',
            'public_session_id' => Str::uuid()->toString(),
            'status' => WidgetSession::STATUS_ACTIVE,
            'skill' => 'askmydocs-assistant@1',
            'mission' => null,
            'page_url' => 'https://allowed.test/account',
            'origin' => 'https://allowed.test',
            'summary' => null,
            'blocked_reason' => null,
            'meta' => null,
        ];
    }

    /** Sessione in stato waiting_tool (pronta per /exec-tool). */
    public function waitingTool(): self
    {
        return $this->state(fn () => [
            'status' => WidgetSession::STATUS_WAITING_TOOL,
        ]);
    }
}
<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Models\KbRetrievalProfile;
use App\Services\Dev\LocalIntegrationFixtureEnvironment;
use App\Support\TenantContext;
use Database\Seeders\CaseStudyUsersSeeder;
use Database\Seeders\LocalIntegrationRetrievalProfilesSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class LocalIntegrationRetrievalProfilesSeederTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $touchedEnvironment = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setEnvironment('APP_ENV', 'local');
        $this->setEnvironment(LocalIntegrationFixtureEnvironment::FLAG, 'true');
        app(TenantContext::class)->set('default');
        $this->seed(RbacSeeder::class);
        $this->seed(CaseStudyUsersSeeder::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->touchedEnvironment as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }

        parent::tearDown();
    }

    public function test_creates_idempotent_retrieval_profiles_for_every_case_study_company(): void
    {
        $seeder = app(LocalIntegrationRetrievalProfilesSeeder::class);
        $seeder->run();
        $seeder->run();

        $profiles = KbRetrievalProfile::query()
            ->orderBy('tenant_id')
            ->get();

        $this->assertCount(3, $profiles);
        $this->assertSame([
            'passolibero-calzature',
            'prometeo-antincendio',
            'rotta-logistics',
        ], $profiles->pluck('tenant_id')->all());
        $this->assertSame($profiles->pluck('tenant_id')->all(), $profiles->pluck('project_key')->all());

        foreach ($profiles as $profile) {
            $this->assertGreaterThanOrEqual(20, mb_strlen($profile->company_context));
            $this->assertNotEmpty($profile->glossary);
            $this->assertNotEmpty($profile->relevant_entities);
            $this->assertNotEmpty($profile->expected_facts);
            $this->assertSame(['markdown', 'email'], $profile->preferred_source_types);
        }

        $rottaProfile = $profiles->firstWhere('tenant_id', 'rotta-logistics');
        $this->assertSame('spedizione', $rottaProfile?->glossary[0]['term']);
        $this->assertContains('RL-TRACK-8842', $rottaProfile?->relevant_entities ?? []);
    }

    private function setEnvironment(string $key, string $value): void
    {
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        $this->touchedEnvironment[] = $key;
    }
}

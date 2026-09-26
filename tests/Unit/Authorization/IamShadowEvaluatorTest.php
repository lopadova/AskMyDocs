<?php

namespace Tests\Unit\Authorization;

use App\Authorization\IamPdp;
use App\Authorization\IamShadowEvaluator;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class IamShadowEvaluatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('iam-shadow', require __DIR__.'/../../../config/iam-shadow.php');
    }

    public function test_manifest_maps_only_known_resources_and_actions(): void
    {
        $evaluator = app(IamShadowEvaluator::class);
        $user = $this->user();

        $known = $evaluator->report($user, 'knowledge_document', 'view');
        $unknown = $evaluator->report($user, 'unknown_resource', 'read');

        $this->assertTrue($known->known);
        $this->assertFalse($unknown->known);
        $this->assertFalse($unknown->allowed);
        $this->assertSame('resource_not_mapped', $unknown->reason);
    }

    public function test_pdp_unavailable_denies_required_operations(): void
    {
        $this->app->instance(IamPdp::class, new class implements IamPdp
        {
            public function decide(User $user, string $resource, string $action, mixed $subject = null): bool
            {
                throw new \RuntimeException('PDP unavailable');
            }
        });

        $report = app(IamShadowEvaluator::class)->report($this->user(), 'knowledge_document', 'edit');

        $this->assertFalse($report->allowed);
        $this->assertSame('pdp_unavailable', $report->reason);
    }

    private function user(): User
    {
        return User::create([
            'name' => 'IAM test',
            'email' => 'iam-'.uniqid('', true).'@example.com',
            'password' => Hash::make('synthetic-test-password'),
        ]);
    }
}

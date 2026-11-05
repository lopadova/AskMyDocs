<?php

namespace Tests\Unit\Actions;

use App\Actions\ActionIntent;
use App\Actions\ActionRegistry;
use PHPUnit\Framework\TestCase;

class ActionIntentTest extends TestCase
{
    public function test_server_constructs_security_fields_and_model_args_cannot_override_them(): void
    {
        $intent = ActionIntent::fromModelProposal(
            actionId: 'act-1',
            tenantId: 'tenant-a',
            principalId: 'user-1',
            proposal: [
                'resource' => 'documents',
                'effect' => 'export',
                'args' => ['document_id' => 10, 'tenant_id' => 'tenant-b', 'assurance' => 'aal0'],
                'target' => 'doc:10',
                'grant' => 'grant-server',
                'assurance' => 'aal2',
            ],
            grant: 'grant-server',
            assurance: 'aal2',
        );

        $this->assertSame('tenant-a', $intent->tenantId);
        $this->assertSame('grant-server', $intent->grant);
        $this->assertSame('aal2', $intent->assurance);
        $this->assertSame(['document_id' => 10], $intent->args);
        $this->assertNotSame('', $intent->argsDigest);
    }

    public function test_unclassified_tool_is_not_executable(): void
    {
        $registry = new ActionRegistry([
            'search_knowledge_base' => ['effect' => 'read', 'executable' => false],
        ]);

        $this->assertFalse($registry->isExecutable('unknown_tool'));
        $this->assertFalse($registry->isExecutable('search_knowledge_base'));
        $this->assertTrue($registry->isKnown('search_knowledge_base'));
    }
}

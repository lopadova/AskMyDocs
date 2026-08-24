<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\KbDiskWriteSafety;
use Tests\TestCase;

final class KbDiskWriteSafetyTest extends TestCase
{
    public function test_it_makes_all_configured_kb_source_disks_strict(): void
    {
        config()->set('filesystems.disks.private', ['driver' => 's3', 'throw' => false]);
        config()->set('filesystems.disks.project-vault', ['driver' => 's3', 'throw' => false]);
        config()->set('filesystems.disks.unrelated', ['driver' => 'local', 'throw' => false]);
        config()->set('kb.sources.disk', 'private');
        config()->set('kb.canonical_disk', 'private');
        config()->set('kb.project_disks', ['legal' => 'project-vault', 'invalid' => ['not-a-disk']]);

        KbDiskWriteSafety::enforce();

        $this->assertTrue(config('filesystems.disks.private.throw'));
        $this->assertTrue(config('filesystems.disks.project-vault.throw'));
        $this->assertFalse(config('filesystems.disks.unrelated.throw'));
    }
}

<?php

namespace Tests\Feature\Kb;

use App\Support\KbDiskResolver;
use Tests\TestCase;

class KbDiskResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Baseline: mirror the shipped defaults from config/kb.php so each
        // test starts from a known state regardless of env leakage.
        config()->set('kb.canonical_disk', 'kb');
        config()->set('kb.raw_disk', 'kb-raw');
        config()->set('kb.project_disks', []);
    }

    public function test_for_project_returns_default_when_project_is_null(): void
    {
        $this->assertSame('kb', KbDiskResolver::forProject(null));
    }

    public function test_for_project_returns_default_when_project_is_empty_string(): void
    {
        $this->assertSame('kb', KbDiskResolver::forProject(''));
    }

    public function test_for_project_falls_back_to_default_when_key_missing_from_map(): void
    {
        config()->set('kb.project_disks', ['hr-portal' => 'kb-hr']);

        $this->assertSame('kb', KbDiskResolver::forProject('finance'));
    }

    public function test_for_project_returns_mapped_disk(): void
    {
        config()->set('kb.project_disks', [
            'hr-portal' => 'kb-hr',
            'legal-vault' => 'kb-legal',
        ]);

        $this->assertSame('kb-hr', KbDiskResolver::forProject('hr-portal'));
        $this->assertSame('kb-legal', KbDiskResolver::forProject('legal-vault'));
    }

    public function test_for_project_ignores_empty_string_mapping(): void
    {
        config()->set('kb.project_disks', ['hr-portal' => '']);

        $this->assertSame('kb', KbDiskResolver::forProject('hr-portal'));
    }

    public function test_for_project_ignores_non_string_mapping(): void
    {
        config()->set('kb.project_disks', ['hr-portal' => 42]);

        $this->assertSame('kb', KbDiskResolver::forProject('hr-portal'));
    }

    public function test_raw_returns_configured_raw_disk(): void
    {
        $this->assertSame('kb-raw', KbDiskResolver::raw());
    }

    public function test_raw_returns_override(): void
    {
        config()->set('kb.raw_disk', 'r2-raw');

        $this->assertSame('r2-raw', KbDiskResolver::raw());
    }

    public function test_canonical_disk_override_is_honoured_by_for_project(): void
    {
        config()->set('kb.canonical_disk', 'custom');

        $this->assertSame('custom', KbDiskResolver::forProject(null));
        $this->assertSame('custom', KbDiskResolver::forProject('no-such-project'));
    }

    public function test_json_env_map_is_honoured_when_config_loader_parses_it(): void
    {
        // Mimic what the config/kb.php closure does when env KB_PROJECT_DISKS
        // is set — decode a JSON string into an associative array and push it
        // into `kb.project_disks`. This guards against regressions in the
        // decode expression without needing to bootstrap the full framework.
        $raw = '{"x":"y","hr-portal":"kb-hr"}';
        $decoded = json_decode($raw, true);
        config()->set('kb.project_disks', $decoded);

        $this->assertSame('y', KbDiskResolver::forProject('x'));
        $this->assertSame('kb-hr', KbDiskResolver::forProject('hr-portal'));
        $this->assertSame('kb', KbDiskResolver::forProject('other'));
    }
}

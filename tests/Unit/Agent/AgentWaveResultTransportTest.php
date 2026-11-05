<?php

declare(strict_types=1);

namespace Tests\Unit\Agent;

use App\Agent\Waves\AgentWaveResultTransport;
use App\Services\Chat\Reasoning\ResearchFailure;
use PHPUnit\Framework\TestCase;

class AgentWaveResultTransportTest extends TestCase
{
    public function test_round_trip_failure_and_invalid_envelopes(): void
    {
        $directory = sys_get_temp_dir().'/transport-test-'.bin2hex(random_bytes(16));
        mkdir($directory, 0700);
        $path = $directory.'/result';
        try {
            AgentWaveResultTransport::write($path, static fn () => "\0\xff");
            $this->assertSame("\0\xff", AgentWaveResultTransport::read($path));
            $this->assertSame(0600, fileperms($path) & 0777);
            AgentWaveResultTransport::write($path, static function () { throw new \RuntimeException('secret'); });
            $this->assertStringNotContainsString('secret', file_get_contents($path));
            $this->assertSame('research_branch_failed', AgentWaveResultTransport::read($path)->reason);
            unlink($path);
            $this->assertSame('research_transport_invalid', AgentWaveResultTransport::read($path)->reason);
            foreach (['', '{\"version\":1', '{}', '{"version":1,"status":"success","result":"%%%"}', '{"version":2,"status":"success","result":"Tjs="}'] as $invalid) {
                file_put_contents($path, $invalid);
                $result = AgentWaveResultTransport::read($path);
                $this->assertInstanceOf(ResearchFailure::class, $result);
                $this->assertSame('research_transport_invalid', $result->reason);
            }
            $file = fopen($path, 'wb');
            ftruncate($file, AgentWaveResultTransport::MAX_BYTES + 1);
            fclose($file);
            $this->assertSame('research_transport_invalid', AgentWaveResultTransport::read($path)->reason);
        } finally {
            @unlink($path);
            rmdir($directory);
        }
    }
}

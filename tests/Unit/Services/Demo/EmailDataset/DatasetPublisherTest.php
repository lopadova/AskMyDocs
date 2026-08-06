<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Demo\EmailDataset;

use App\Services\Demo\EmailDataset\DatasetPublisher;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use Tests\TestCase;

final class DatasetPublisherTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        $files = new Filesystem;
        foreach (array_reverse($this->temporaryDirectories) as $directory) {
            if (is_link($directory)) {
                $this->assertTrue(unlink($directory));

                continue;
            }

            if (is_dir($directory)) {
                $this->assertTrue($files->deleteDirectory($directory));
            }
        }

        parent::tearDown();
    }

    public function test_force_refuses_a_destination_symlink_without_touching_its_target(): void
    {
        $root = $this->temporaryDirectory('root');
        $outside = $this->temporaryDirectory('outside');
        $marker = $outside.'/must-survive.txt';
        $this->assertNotFalse(file_put_contents($marker, 'preserve me'));

        $destination = $root.'/case-study-email-v2-large-s1-catalogv1';
        $this->assertTrue(symlink($outside, $destination));
        $this->temporaryDirectories[] = $destination;

        try {
            (new DatasetPublisher)->assertCanGenerate(
                destination: $destination,
                force: true,
                check: false,
            );
            $this->fail('A symbolic-link dataset destination was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('symbolic link', $exception->getMessage());
        }

        $this->assertFileExists($marker);
        $this->assertSame('preserve me', file_get_contents($marker));
    }

    public function test_discard_refuses_a_directory_symlink_without_following_it(): void
    {
        $outside = $this->temporaryDirectory('outside-discard');
        $marker = $outside.'/must-survive.txt';
        $this->assertNotFalse(file_put_contents($marker, 'preserve me'));
        $link = sys_get_temp_dir().'/askmydocs-publisher-link-'.bin2hex(random_bytes(8));
        $this->assertTrue(symlink($outside, $link));
        $this->temporaryDirectories[] = $link;

        try {
            (new DatasetPublisher)->discard($link);
            $this->fail('Discard followed a symbolic-link dataset directory.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('symbolic-link', $exception->getMessage());
        }

        $this->assertFileExists($marker);
    }

    public function test_force_never_replaces_an_existing_version_with_different_bytes(): void
    {
        $root = $this->temporaryDirectory('immutable');
        $publisher = new DatasetPublisher;
        $version = 'case-study-email-v2-large-s1-catalogv1';
        $destination = $publisher->destination($root, $version);
        $this->assertTrue(mkdir($destination, 0755, true));
        $this->assertNotFalse(file_put_contents($destination.'/manifest.json', "published\n"));
        $temporary = $publisher->createTemporaryDirectory($root, $version);
        $this->assertNotFalse(file_put_contents($temporary.'/manifest.json', "different\n"));

        try {
            $publisher->publish($temporary, $destination, true);
            $this->fail('A different artifact replaced the same immutable dataset version.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('immutable dataset version', $exception->getMessage());
        }

        $this->assertSame("published\n", file_get_contents($destination.'/manifest.json'));
        $this->assertDirectoryDoesNotExist($temporary);
    }

    private function temporaryDirectory(string $label): string
    {
        $directory = sys_get_temp_dir()."/askmydocs-publisher-{$label}-".bin2hex(random_bytes(8));
        $this->assertTrue(mkdir($directory, 0755, true));
        $this->temporaryDirectories[] = $directory;

        return $directory;
    }
}

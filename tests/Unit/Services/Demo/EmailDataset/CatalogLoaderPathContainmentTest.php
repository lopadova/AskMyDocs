<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Demo\EmailDataset;

use App\Services\Demo\EmailDataset\CatalogLoader;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

/**
 * The dataset guard has to reject paths outside its approved roots, and accept
 * the approved ones, on every platform.
 *
 * It did neither. The prefixes were built with `DIRECTORY_SEPARATOR` while the
 * paths themselves are assembled with a literal `/` throughout
 * `CatalogLoader`. On Linux the two coincide and the comparison worked by
 * accident; on Windows the path reads `C:\…\AskMyDocs/database/…`, nothing
 * matched, and a guard meant to reject unapproved paths rejected the approved
 * ones too — 15 tests red for anyone developing on Windows while CI stayed
 * green. That asymmetry is why it survived.
 *
 * **The tests are platform-split on purpose, because the behaviour is.**
 * Folding backslashes is correct where a backslash is a separator and unsafe
 * where it is a legal filename character: on POSIX a real directory named
 * `email-dataset\evil` would fold into `email-dataset/evil` and a path outside
 * the root would be accepted as inside it. So each platform asserts the
 * property that is true of it, rather than a shared one that would be a lie on
 * one of them.
 *
 * They drive the private helper through reflection deliberately. Going through
 * the public path would only ever use the paths the running machine produces —
 * exactly the blind spot that let this live.
 */
final class CatalogLoaderPathContainmentTest extends TestCase
{
    private function label(CatalogLoader $loader, string $path): string
    {
        return (new ReflectionMethod($loader, 'snapshotLabel'))->invoke($loader, $path);
    }

    private function posixLoader(): CatalogLoader
    {
        return new CatalogLoader('/srv/app/database/seeders/email-dataset');
    }

    public function test_a_posix_root_accepts_a_posix_path(): void
    {
        $this->assertSame(
            'email-dataset/catalogs/v1/demo.json',
            $this->label(
                $this->posixLoader(),
                '/srv/app/database/seeders/email-dataset/catalogs/v1/demo.json',
            ),
        );
    }

    public function test_it_rejects_a_path_outside_the_roots(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('outside approved roots');

        $this->label($this->posixLoader(), '/etc/passwd');
    }

    public function test_a_sibling_directory_sharing_the_prefix_is_rejected(): void
    {
        // `…/email-dataset-evil` starts with the root STRING but is a different
        // directory. The trailing separator on the prefix is what separates
        // them, and normalising must not have dropped it.
        $this->expectException(RuntimeException::class);

        $this->label($this->posixLoader(), '/srv/app/database/seeders/email-dataset-evil/leak.json');
    }

    public function test_on_posix_a_backslash_stays_an_ordinary_character(): void
    {
        // The reason folding is NOT unconditional. `email-dataset\evil` is a
        // legal POSIX directory name and is outside the root; folding it would
        // turn it into `email-dataset/evil` and admit it. This is the assertion
        // CI runs, so the safety property is the one actually verified.
        if (DIRECTORY_SEPARATOR !== '/') {
            $this->markTestSkipped('Backslash is a separator on this platform, not a filename character.');
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('outside approved roots');

        $this->label($this->posixLoader(), '/srv/app/database/seeders/email-dataset\\evil/leak.json');
    }

    public function test_on_windows_a_native_root_accepts_a_forward_slash_path(): void
    {
        // The exact shape that was failing: a native-separator root, and a path
        // built by joining onto it with '/'. Meaningful only where '\' is the
        // separator — which is the platform the bug was on.
        if (DIRECTORY_SEPARATOR === '/') {
            $this->markTestSkipped('Only meaningful where the native separator is a backslash.');
        }

        $root = 'C:'.DIRECTORY_SEPARATOR.'srv'.DIRECTORY_SEPARATOR.'app'
            .DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'seeders'
            .DIRECTORY_SEPARATOR.'email-dataset';

        $this->assertSame(
            'email-dataset/catalogs/v1/demo.json',
            $this->label(new CatalogLoader($root), $root.'/catalogs/v1/demo.json'),
        );
    }
}

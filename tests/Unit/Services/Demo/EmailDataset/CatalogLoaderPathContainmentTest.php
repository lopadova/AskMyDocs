<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Demo\EmailDataset;

use App\Services\Demo\EmailDataset\CatalogLoader;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

/**
 * The dataset guard has to reject paths outside its approved roots on every
 * platform, and accept the approved ones on every platform.
 *
 * It did neither. The prefixes were built with `DIRECTORY_SEPARATOR` while the
 * paths themselves are assembled with literal `/` throughout `CatalogLoader`.
 * On Linux the two coincide and the comparison worked by accident; on Windows
 * the path reads `C:\…\AskMyDocs/database/…` and every check failed — so a
 * guard meant to reject unapproved paths rejected the approved ones too, and
 * 15 tests were red for anyone developing on Windows while CI stayed green.
 *
 * These tests drive the private helper through reflection on purpose. Going
 * through the public path would exercise it only with paths this machine
 * happens to produce, which is exactly the blind spot that let the bug live:
 * on CI it would pass whether or not the fix is present, and the fix would be
 * unprotected on the only platform where it matters.
 */
final class CatalogLoaderPathContainmentTest extends TestCase
{
    private function label(CatalogLoader $loader, string $path): string
    {
        $method = new ReflectionMethod($loader, 'snapshotLabel');

        return $method->invoke($loader, $path);
    }

    public function test_a_windows_style_root_accepts_a_forward_slash_path(): void
    {
        // The exact shape that failed: a native-separator root, and a path
        // built by joining onto it with '/'.
        $loader = new CatalogLoader('C:\\srv\\app\\database\\seeders\\email-dataset');

        $this->assertSame(
            'email-dataset/catalogs/v1/demo.json',
            $this->label($loader, 'C:\\srv\\app\\database\\seeders\\email-dataset/catalogs/v1/demo.json'),
        );
    }

    public function test_a_posix_root_still_accepts_a_posix_path(): void
    {
        $loader = new CatalogLoader('/srv/app/database/seeders/email-dataset');

        $this->assertSame(
            'email-dataset/catalogs/v1/demo.json',
            $this->label($loader, '/srv/app/database/seeders/email-dataset/catalogs/v1/demo.json'),
        );
    }

    public function test_it_still_rejects_a_path_outside_the_roots(): void
    {
        // The guard must not have been widened into uselessness by making it
        // separator-neutral: something genuinely outside is still refused.
        $loader = new CatalogLoader('/srv/app/database/seeders/email-dataset');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('outside approved roots');

        $this->label($loader, '/etc/passwd');
    }

    public function test_a_sibling_directory_sharing_the_prefix_is_rejected(): void
    {
        // `/srv/app/…/email-dataset-evil` starts with the root STRING but is a
        // different directory. The trailing separator on the prefix is what
        // separates the two, and normalising must not have dropped it.
        $loader = new CatalogLoader('/srv/app/database/seeders/email-dataset');

        $this->expectException(RuntimeException::class);

        $this->label($loader, '/srv/app/database/seeders/email-dataset-evil/leak.json');
    }
}

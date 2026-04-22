<?php

/*
 * PHPUnit bootstrap — ensure Orchestra Testbench working directories exist.
 *
 * Testbench v11 compiles a services manifest into its fixture "bootstrap/cache"
 * folder the first time the Laravel container is created. On a freshly
 * installed repo that folder does not exist yet and the ProviderRepository
 * throws "The ... bootstrap/cache directory must be present and writable."
 *
 * Creating it here (idempotent) fixes the error without requiring developers
 * to run a post-install script.
 */

require __DIR__.'/../vendor/autoload.php';

$cacheDir = __DIR__.'/../vendor/orchestra/testbench-core/laravel/bootstrap/cache';

if (! is_dir($cacheDir)) {
    // Do not suppress errors — a silent failure here surfaces as an
    // unrelated ProviderRepository exception deep inside the first test.
    if (! mkdir($cacheDir, 0755, true) && ! is_dir($cacheDir)) {
        fwrite(STDERR, "Failed to create Testbench cache dir: {$cacheDir}\n");
        exit(1);
    }
}

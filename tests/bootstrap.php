<?php

/*
 * PHPUnit bootstrap — ensure Orchestra Testbench working directories exist.
 *
 * Testbench v9 compiles a services manifest into its fixture "bootstrap/cache"
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
    @mkdir($cacheDir, 0777, true);
}

<?php

/**
 * Testbench mirror of the production data migration. Keeping one source of
 * truth also ensures migration-level tests exercise the production code.
 */
return require __DIR__.'/../../../database/migrations/2026_07_28_000001_split_system_admin_role.php';

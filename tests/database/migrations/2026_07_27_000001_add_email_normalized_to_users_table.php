<?php

/**
 * Testbench mirror: execute the production migration so bounded backfill and
 * collision handling cannot drift between SQLite tests and deployment.
 */
return require __DIR__.'/../../../database/migrations/2026_07_27_000001_add_email_normalized_to_users_table.php';

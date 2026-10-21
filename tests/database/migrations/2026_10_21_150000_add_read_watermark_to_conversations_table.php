<?php

// Exercise the same schema and legacy-history backfill as production.
return require __DIR__.'/../../../database/migrations/2026_10_21_150000_add_read_watermark_to_conversations_table.php';

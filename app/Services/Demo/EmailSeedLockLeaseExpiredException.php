<?php

declare(strict_types=1);

namespace App\Services\Demo;

use RuntimeException;

/**
 * The physical-mailbox lock can no longer safely fence IMAP or checkpoint I/O.
 *
 * Callers must stop immediately. A generated dataset remains resumable because
 * an APPEND acknowledged after ownership loss never advances its checkpoint.
 */
final class EmailSeedLockLeaseExpiredException extends RuntimeException {}

<?php

declare(strict_types=1);

namespace App\Support\Kb;

use RuntimeException;

/**
 * A cache lock the critical section relied on is no longer owned by this
 * process (its TTL lapsed while the work ran, or the store lost it): the
 * irreversible step behind it — a delete, a publish, a row commit — is
 * refused rather than run unguarded (ADR 0030 §3).
 */
final class LockLostException extends RuntimeException {}

<?php

namespace App\Services\Admin\Exceptions;

use RuntimeException;

/**
 * Thrown by {@see \App\Services\Admin\LogTailService} when the log
 * file exists but can't be read (permission drop, filesystem error,
 * stat failure, SplFileObject crash). The controller maps this to
 * HTTP 500.
 *
 * Pair of LogFileNotFoundException — together they replace the
 * previous brittle "message prefix sniffing" branching (Copilot #7).
 */
class LogFileUnreadableException extends RuntimeException
{
}

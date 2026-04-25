<?php

namespace App\Services\Admin\Exceptions;

use RuntimeException;

/**
 * Thrown by {@see \App\Services\Admin\LogTailService} when the caller
 * asks for a log file that doesn't exist on disk.
 *
 * The controller maps this to HTTP 404. Distinct class (instead of a
 * generic RuntimeException + message-prefix sniffing) so the status
 * mapping stays stable when the message copy drifts (Copilot #7).
 */
class LogFileNotFoundException extends RuntimeException
{
}

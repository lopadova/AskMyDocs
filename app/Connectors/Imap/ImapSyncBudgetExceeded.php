<?php

declare(strict_types=1);

namespace App\Connectors\Imap;

use RuntimeException;
use Throwable;

final class ImapSyncBudgetExceeded extends RuntimeException
{
    /** Webklex and the package wrap connection/response exceptions. */
    public static function causedBy(Throwable $exception): bool
    {
        do {
            if ($exception instanceof self) {
                return true;
            }
            $exception = $exception->getPrevious();
        } while ($exception !== null);

        return false;
    }
}

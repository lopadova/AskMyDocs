<?php

declare(strict_types=1);

namespace App\Services\Demo;

use Closure;
use RuntimeException;
use Webklex\PHPIMAP\Connection\Protocols\ImapProtocol;
use Webklex\PHPIMAP\IMAP;
use WeakMap;

/**
 * Marks one bounded page of seed-message UIDs and confirms only those exact
 * deletions with UID EXPUNGE. The completion callback is intentionally
 * post-expunge.
 */
final class ImapUidBatchPurger
{
    /** @var WeakMap<ImapProtocol, true> */
    private WeakMap $uidPlusConnections;

    public function __construct()
    {
        $this->uidPlusConnections = new WeakMap;
    }

    /**
     * @param  list<int>  $uids
     * @param  Closure(int): void|null  $onPurged
     */
    public function purge(
        ImapProtocol $connection,
        string $folderPath,
        array $uids,
        ?EmailSeedLockLease $lease = null,
        ?Closure $onPurged = null,
    ): int {
        $ranges = ImapUidRanges::compress($uids);
        if ($ranges === []) {
            return 0;
        }
        $uidSet = ImapUidRanges::sequenceSet($ranges);

        $this->assertUidPlusSupported($connection);

        // The preceding Client::getConnection() may have transparently
        // reconnected after a failed NOOP. Select on this exact protocol
        // instance before issuing UID STORE or UID EXPUNGE.
        $connection->selectFolder($folderPath)->validatedData();

        $deleted = 0;
        foreach ($ranges as [$from, $to]) {
            $lease?->refresh();
            $marked = $connection
                ->store(['\\Deleted'], $from, $to, '+', true, IMAP::ST_UID)
                ->validatedData();
            if (! $marked) {
                throw new RuntimeException(
                    "UID STORE IMAP fallito per l'intervallo {$from}:{$to}.",
                );
            }

            $deleted += $to - $from + 1;
        }

        $lease?->refresh();
        $expunged = $connection
            ->requestAndResponse('UID EXPUNGE', [$uidSet])
            ->validatedData();
        if ($expunged === false) {
            throw new RuntimeException(
                "UID EXPUNGE IMAP fallito per l'insieme {$uidSet}.",
            );
        }
        $lease?->refresh();

        if ($onPurged !== null) {
            $onPurged($deleted);
        }

        return $deleted;
    }

    private function assertUidPlusSupported(ImapProtocol $connection): void
    {
        if (isset($this->uidPlusConnections[$connection])) {
            return;
        }

        $capabilities = $connection->getCapabilities()->validatedData();
        $normalized = is_array($capabilities)
            ? array_map(
                static fn (mixed $capability): string => strtoupper((string) $capability),
                $capabilities,
            )
            : [];
        if (! in_array('UIDPLUS', $normalized, true)) {
            throw new RuntimeException(
                'Il server IMAP non supporta UIDPLUS: purge selettivo annullato prima di UID STORE.',
            );
        }

        $this->uidPlusConnections[$connection] = true;
    }
}

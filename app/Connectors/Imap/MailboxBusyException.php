<?php

declare(strict_types=1);

namespace App\Connectors\Imap;

use RuntimeException;

/**
 * Thrown when a NEW IMAP connection cannot acquire the per-mailbox lock within the
 * wait window — i.e. another connection to the same account is already live and did
 * not free in time. On the synchronous HTTP surfaces (folder picker / test-fetch) it
 * is caught by their generic "couldn't reach the mailbox" → 503 handler, so it
 * surfaces as a 503 whose MESSAGE carries the busy reason (not a dedicated status
 * code). Sync jobs avoid it almost entirely by re-queuing at the job layer
 * (WithoutOverlapping), so a busy mailbox there is a re-queue, not an error.
 */
final class MailboxBusyException extends RuntimeException {}

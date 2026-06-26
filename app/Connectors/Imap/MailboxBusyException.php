<?php

declare(strict_types=1);

namespace App\Connectors\Imap;

use RuntimeException;

/**
 * Thrown when a NEW IMAP connection cannot acquire the per-mailbox lock within the
 * wait window — i.e. another connection to the same account is already live and did
 * not free in time. The HTTP surfaces catch it and map it to 503 "mailbox busy,
 * retry" (R14 — distinct from "couldn't reach the mailbox"); sync jobs avoid it
 * almost entirely by re-queuing at the job layer (WithoutOverlapping).
 */
final class MailboxBusyException extends RuntimeException {}

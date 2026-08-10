<?php

declare(strict_types=1);

namespace App\Support\Http;

use RuntimeException;

/**
 * Thrown when an outbound URL fails the SSRF allow-list (SEC-SSRF-001):
 * disallowed scheme, embedded credentials, an obfuscated numeric host, or a
 * host that resolves to a private / loopback / link-local / cloud-metadata
 * address. (A host that simply does not resolve is allowed — it reaches
 * nothing — so it is not an error here.)
 *
 * The message is safe to log (it names the reason and a bounded host, never
 * secrets). It is a permanent failure — callers must NOT retry.
 */
class UnsafeOutboundUrlException extends RuntimeException
{
}

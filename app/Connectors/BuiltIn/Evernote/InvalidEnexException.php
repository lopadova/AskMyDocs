<?php

declare(strict_types=1);

namespace App\Connectors\BuiltIn\Evernote;

/**
 * v4.5/W4 — Raised by {@see EnexImporter::import()} when the uploaded
 * `.enex` file is missing, unreadable, malformed XML, or simply not an
 * Evernote export (root element is not `<en-export>`).
 *
 * The controller (`EvernoteEnexController`) catches this exception and
 * maps it to HTTP 422 + a structured error payload — R14 forbids
 * silently returning 200 on a parse failure.
 */
class InvalidEnexException extends \RuntimeException {}

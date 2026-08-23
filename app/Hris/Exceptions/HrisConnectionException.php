<?php

declare(strict_types=1);

namespace App\Hris\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Thrown when an HRIS could not be reached or answered unusably (network error,
 * auth rejection, malformed payload).
 *
 * Unlike {@see HrisUnsupportedOperation} this IS transient — a sync run that
 * hits it should be recorded as `failed` on the SyncRun and is safe to retry.
 * Adapters wrap vendor/HTTP exceptions in this so callers never have to catch
 * Guzzle or vendor-specific types; that leakage is exactly what the port exists
 * to prevent.
 */
final class HrisConnectionException extends RuntimeException
{
    public static function for(string $adapter, string $reason, ?Throwable $previous = null): self
    {
        return new self("The [{$adapter}] HRIS adapter could not complete the request: {$reason}", 0, $previous);
    }
}

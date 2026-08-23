<?php

declare(strict_types=1);

namespace App\Hris\Exceptions;

use RuntimeException;

/**
 * Thrown when an HRIS implements the port but genuinely cannot perform the
 * requested operation.
 *
 * This is NOT an error condition to be retried — it is a permanent statement of
 * capability. The canonical case: ExampleHR exposes no training-completion
 * endpoint in its public documentation, so `ExampleHrAdapter::pushTrainingCompletion()`
 * throws this rather than silently no-oping (which would make Sprint 6's outbox
 * believe a write-back succeeded when nothing was recorded anywhere).
 *
 * Sprint 6's outbox worker will catch this and mark the event `unsupported`
 * (a terminal state distinct from `failed`) so it is never retried.
 */
final class HrisUnsupportedOperation extends RuntimeException
{
    /**
     * Name the adapter and the operation, so the message is actionable in a log
     * line without needing the stack trace to work out which HRIS refused.
     */
    public static function for(string $adapter, string $operation): self
    {
        return new self("The [{$adapter}] HRIS adapter does not support [{$operation}].");
    }
}

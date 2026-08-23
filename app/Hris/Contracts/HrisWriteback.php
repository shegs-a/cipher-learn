<?php

declare(strict_types=1);

namespace App\Hris\Contracts;

use App\Hris\Data\TrainingCompletionData;
use App\Hris\Exceptions\HrisConnectionException;
use App\Hris\Exceptions\HrisUnsupportedOperation;

/**
 * The write side of the HRIS port: recording training completion back against
 * the employee's HR record.
 *
 * This closes the product loop — a shortfall is identified, training assigned,
 * and the completion written back where the performance conversation happens.
 *
 * Crucially, implementing this interface is NOT a promise that the HR system can
 * actually do it. ExampleHR publishes no training-completion endpoint, so its
 * adapter implements this method by throwing {@see HrisUnsupportedOperation}.
 * That is the honest modelling: the capability is absent, and the caller finds
 * out loudly rather than believing a silent no-op succeeded.
 */
interface HrisWriteback
{
    /**
     * Record a completed training against the employee's HR record.
     *
     * @throws HrisUnsupportedOperation when the HR system has no such capability.
     *                                  Permanent — callers must not retry.
     * @throws HrisConnectionException when it does, but the call failed. Transient.
     */
    public function pushTrainingCompletion(TrainingCompletionData $completion): void;

    /**
     * Whether this adapter can genuinely perform a write-back.
     *
     * Lets Sprint 6's outbox decide up front whether to even queue an event,
     * instead of discovering the answer by catching an exception per record.
     */
    public function supportsWriteback(): bool;
}

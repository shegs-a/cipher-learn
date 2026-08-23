<?php

declare(strict_types=1);

namespace App\Hris\Data;

use DateTimeImmutable;

/**
 * A completed piece of training, ready to be written back to the HR system.
 *
 * Defined now so the port is whole and `HrisWriteback` has a real signature, but
 * not actually produced until Sprint 6 (transactional outbox). Sprint 2 only
 * needs it to exist so adapters can declare their write-back capability — or, in
 * ExampleHR's case, honestly declare the absence of one.
 *
 * Identifies the person by `employeeExternalId` (the HRIS's own key), never by
 * our local ULID — the HR system has never heard of our primary keys.
 */
final readonly class TrainingCompletionData
{
    public function __construct(
        public string $employeeExternalId,
        public string $courseTitle,
        public DateTimeImmutable $completedAt,
        /** Percentage score where the course was assessed, null where it was not. */
        public ?int $score = null,
        /** Public verification serial of the issued certificate, when there is one. */
        public ?string $certificateSerial = null,
    ) {}
}

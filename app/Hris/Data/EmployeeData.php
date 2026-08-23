<?php

declare(strict_types=1);

namespace App\Hris\Data;

use App\Enums\EmployeeStatus;

/**
 * A person as described by an HR system, in OUR vocabulary — not the vendor's.
 *
 * This is the neutral currency of the HRIS port. Every adapter maps its own
 * payload into this shape, so nothing vendor-specific (ExampleHR field names,
 * nesting, status codes) ever leaks past the adapter boundary into the sync,
 * the models, or anything downstream. Swapping HR systems means writing one new
 * adapter, not touching the rest of the app.
 *
 * Readonly + constructor-promoted: a DTO carried across a boundary should not be
 * mutable, and PHP 8.3 gives us that without pulling in a package.
 *
 * `managerExternalId` is deliberately the manager's EXTERNAL id, not a local
 * `employees.id` — the adapter has no knowledge of our primary keys, and a
 * manager may not have been persisted yet when their report is streamed. The
 * sync resolves external ids to local ULIDs in a second pass.
 */
final readonly class EmployeeData
{
    public function __construct(
        /** Stable identifier from the HRIS; the join key for sync and write-back. */
        public string $externalId,
        public string $firstName,
        public string $lastName,
        public ?string $email = null,
        public ?string $department = null,
        public ?string $jobTitle = null,
        public ?string $location = null,
        /** External id of this person's manager, or null at the top of the tree. */
        public ?string $managerExternalId = null,
        /** Normalised by the adapter — vendor status codes never reach us raw. */
        public EmployeeStatus $status = EmployeeStatus::Active,
    ) {}

    /**
     * The subset of columns the sync is allowed to write to `employees`.
     *
     * Returned as an explicit list on purpose (see the Sprint 2 plan): the sync
     * must never blanket-overwrite an employee row, because Sprint 3 adds
     * `employees.user_id` linking a synced person to their login. Writing only
     * the keys below means a re-sync can never clobber that link, or any other
     * locally-owned column added later.
     *
     * @return array{first_name: string, last_name: string, email: string|null, department: string|null, job_title: string|null, location: string|null, status: string}
     */
    public function toEmployeeAttributes(): array
    {
        return [
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'email' => $this->email,
            'department' => $this->department,
            'job_title' => $this->jobTitle,
            'location' => $this->location,
            'status' => $this->status->value,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Assignment;

use App\Enums\AssignmentSkipReason;

/**
 * The structured outcome of a bulk assignment (org-wide, department or a selected
 * set). One employee being blocked or skipped never fails the batch; everything
 * else carries on, and this reports exactly what happened to everyone.
 *
 *   143 targeted · 126 assigned · 12 blocked — on leave · 5 skipped — already assigned
 */
final class AssignmentResult
{
    /** @var list<AssignmentItemResult> */
    private array $items = [];

    public function add(AssignmentItemResult $item): self
    {
        $this->items[] = $item;

        return $this;
    }

    /** @return list<AssignmentItemResult> */
    public function items(): array
    {
        return $this->items;
    }

    public function total(): int
    {
        return count($this->items);
    }

    public function assigned(): int
    {
        return count(array_filter($this->items, fn (AssignmentItemResult $i): bool => $i->isAssigned()));
    }

    public function blocked(): int
    {
        return count($this->blockedItems());
    }

    public function skipped(): int
    {
        return count(array_filter($this->items, fn (AssignmentItemResult $i): bool => $i->isSkipped()));
    }

    public function skippedAlreadyAssigned(): int
    {
        return $this->countSkipped(AssignmentSkipReason::AlreadyAssigned);
    }

    /** Skipped for any reason other than "already assigned". */
    public function skippedOther(): int
    {
        return $this->skipped() - $this->skippedAlreadyAssigned();
    }

    /** Assigned although on leave (the tenant allows it) — worth surfacing. */
    public function assignedWhileOnLeave(): int
    {
        return count(array_filter($this->items, fn (AssignmentItemResult $i): bool => $i->assignedWhileOnLeave));
    }

    public function hasBlocked(): bool
    {
        return $this->blocked() > 0;
    }

    /** True when any leave check ran on out-of-date leave data. */
    public function leaveDataStale(): bool
    {
        return count(array_filter($this->items, fn (AssignmentItemResult $i): bool => $i->leaveDataStale)) > 0
            || $this->staleFlag;
    }

    private bool $staleFlag = false;

    /** Mark the whole operation as having used stale leave data (nobody on leave was found, but the data was old). */
    public function markLeaveDataStale(): self
    {
        $this->staleFlag = true;

        return $this;
    }

    /** @return list<AssignmentItemResult> */
    public function blockedItems(): array
    {
        return array_values(array_filter($this->items, fn (AssignmentItemResult $i): bool => $i->isBlocked()));
    }

    /**
     * Enough for an admin to see who was excluded and why.
     *
     * @return list<array<string, mixed>>
     */
    public function blockedEmployees(): array
    {
        return array_map(fn (AssignmentItemResult $i): array => $i->toArray(), $this->blockedItems());
    }

    /** @return list<array<string, mixed>> */
    public function skippedEmployees(): array
    {
        return array_values(array_map(
            fn (AssignmentItemResult $i): array => $i->toArray(),
            array_filter($this->items, fn (AssignmentItemResult $i): bool => $i->isSkipped()),
        ));
    }

    /** "126 assigned · 12 blocked — on leave · 5 skipped — already assigned" */
    public function summary(): string
    {
        $parts = [$this->assigned().' assigned'];

        if ($this->blocked() > 0) {
            $parts[] = $this->blocked().' blocked — on leave';
        }

        if ($this->skippedAlreadyAssigned() > 0) {
            $parts[] = $this->skippedAlreadyAssigned().' skipped — already assigned';
        }

        if ($this->skippedOther() > 0) {
            $parts[] = $this->skippedOther().' skipped — other';
        }

        return implode(' · ', $parts);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'total' => $this->total(),
            'assigned' => $this->assigned(),
            'blocked' => $this->blocked(),
            'skipped' => $this->skipped(),
            'skipped_already_assigned' => $this->skippedAlreadyAssigned(),
            'skipped_other' => $this->skippedOther(),
            'assigned_while_on_leave' => $this->assignedWhileOnLeave(),
            'leave_data_stale' => $this->leaveDataStale(),
            'blocked_employees' => $this->blockedEmployees(),
            'skipped_employees' => $this->skippedEmployees(),
        ];
    }

    private function countSkipped(AssignmentSkipReason $reason): int
    {
        return count(array_filter(
            $this->items,
            fn (AssignmentItemResult $i): bool => $i->isSkipped() && $i->skipReason === $reason,
        ));
    }
}

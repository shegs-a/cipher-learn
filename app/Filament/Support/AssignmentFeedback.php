<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Assignment\AssignmentItemResult;
use App\Assignment\AssignmentResult;
use Filament\Notifications\Notification;

/**
 * Renders the outcome of an assignment in the admin panel. A thin presentation
 * layer over {@see AssignmentResult} / {@see AssignmentItemResult}: all the
 * deciding happens in the domain services, the UI just tells the admin what
 * happened — including who was blocked and why, so nothing is silently dropped.
 */
final class AssignmentFeedback
{
    /** How many blocked employees to name in the toast before "and N more". */
    private const NAMES_SHOWN = 5;

    /** The toast for a bulk assignment. */
    public static function bulk(AssignmentResult $result, string $title): Notification
    {
        $lines = [$result->total().' targeted — '.$result->summary().'.'];

        if ($result->hasBlocked()) {
            $blocked = $result->blockedItems();
            $shown = array_slice($blocked, 0, self::NAMES_SHOWN);

            $lines[] = 'Blocked (on leave): '.implode('; ', array_map(
                fn (AssignmentItemResult $i): string => $i->employee->full_name.' ('.$i->leavePeriod().')',
                $shown,
            )).(count($blocked) > count($shown) ? '; and '.(count($blocked) - count($shown)).' more' : '').'.';
            $lines[] = 'Your organisation’s policy does not allow assignments to employees on leave.';
        }

        if ($result->assignedWhileOnLeave() > 0) {
            $lines[] = $result->assignedWhileOnLeave().' assigned while on leave (allowed by your policy).';
        }

        if ($result->leaveDataStale()) {
            $lines[] = 'Leave data is out of date, so the leave check may be incomplete.';
        }

        $notification = Notification::make()->title($title)->body(implode("\n", $lines));

        // Anything blocked needs a human's attention: keep it on screen.
        return $result->hasBlocked()
            ? $notification->warning()->persistent()
            : $notification->success();
    }

    /** The toast for a single assignment (e.g. approving one request). */
    public static function single(AssignmentItemResult $result, string $subjectName): Notification
    {
        $notification = Notification::make()->body($result->message($subjectName));

        return match (true) {
            $result->isBlocked() => $notification
                ->title('Not assigned — employee is on leave')
                ->body($result->message($subjectName).' Your organisation’s policy does not allow assignments to employees on leave.')
                ->warning()
                ->persistent(),
            $result->isSkipped() => $notification->title('Nothing to assign')->warning(),
            default => $notification->title('Assigned')->success(),
        };
    }
}

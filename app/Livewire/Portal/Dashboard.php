<?php

declare(strict_types=1);

namespace App\Livewire\Portal;

use App\Enums\EnrollmentStatus;
use App\Models\Employee;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The learner's landing: "My Learning" — their own assigned training, each item
 * carrying the reason it was assigned (the product's whole point).
 *
 * Sprint 3 stands this up as the non-admin destination of the unified login,
 * styled to the approved learner-app design, and wired to real data (the
 * signed-in user → their Employee → enrolments, each with its rationale). The
 * deeper journey the design also shows — course detail, lessons, quizzes,
 * results, chat — is Sprint 5.
 *
 * Tenant context is set by BindCurrentTenant on the web middleware, so the
 * Employee lookup and its enrolments are automatically tenant-scoped.
 */
#[Layout('components.layouts.app')]
class Dashboard extends Component
{
    public function render(): View
    {
        /** @var User $user */
        $user = auth()->user();

        $employee = $user->employee;

        /** @var Collection<int, Enrollment> $enrollments */
        $enrollments = $employee instanceof Employee
            ? $employee->enrollments()
                ->with(['course' => fn ($q) => $q->withCount('lessons')])
                // Eager-load progress so completionPercent() stays N+1-free across
                // the card list and the "overall progress" tile.
                ->with('lessonProgress')
                ->orderByRaw('due_at is null, due_at asc')
                ->get()
            : new Collection;

        // Requested enrolments are pending, not yet real assignments — they show
        // in the list but don't count toward the assigned/overdue tiles.
        $active = $enrollments->reject(
            fn (Enrollment $e) => in_array($e->status, [
                EnrollmentStatus::Cancelled, EnrollmentStatus::Waived, EnrollmentStatus::Requested,
            ], true)
        );

        // Chrome (nav, account switcher, notifications bell) now lives in the shared
        // <x-portal.shell>, which derives its own data from the user — so this
        // component only supplies the page's own content.
        return view('livewire.portal.dashboard', [
            'user' => $user,
            'greeting' => $this->greeting(),
            'enrollments' => $enrollments,
            'assignedCount' => $active->count(),
            'overdueCount' => $active->filter(fn (Enrollment $e) => $this->isOverdue($e))->count(),
            'overallProgress' => (int) round((float) $active->avg(fn (Enrollment $e) => $this->progress($e))),
            'certificatesCount' => $active->where('status', EnrollmentStatus::Completed)->count(),
        ]);
    }

    /** Time-of-day greeting, like the design's "Good morning". */
    public function greeting(): string
    {
        $hour = (int) now()->format('G');

        return match (true) {
            $hour < 12 => 'Good morning,',
            $hour < 17 => 'Good afternoon,',
            default => 'Good evening,',
        };
    }

    private function isOverdue(Enrollment $enrollment): bool
    {
        return $enrollment->status !== EnrollmentStatus::Completed
            && $enrollment->due_at !== null
            && $enrollment->due_at->isPast();
    }

    /**
     * The urgency pill for a course card: label + Tailwind classes, mirroring the
     * design's OVERDUE / DUE IN N DAYS / ON TRACK states.
     *
     * @return array{label: string, classes: string}
     */
    public function pill(Enrollment $enrollment): array
    {
        if ($enrollment->status === EnrollmentStatus::Requested) {
            return ['label' => 'PENDING APPROVAL', 'classes' => 'bg-gray-100 text-gray-500'];
        }

        if ($enrollment->status === EnrollmentStatus::Completed) {
            return ['label' => 'COMPLETED', 'classes' => 'bg-green-100 text-green-700'];
        }

        $due = $enrollment->due_at;

        if ($due === null) {
            return ['label' => 'NO DUE DATE', 'classes' => 'bg-gray-100 text-gray-500'];
        }

        $days = (int) Carbon::now()->startOfDay()->diffInDays($due->copy()->startOfDay(), false);

        return match (true) {
            $days < 0 => ['label' => 'OVERDUE · '.abs($days).' '.Str::plural('DAY', abs($days)), 'classes' => 'bg-red-100 text-red-700'],
            $days <= 7 => ['label' => 'DUE IN '.$days.' '.Str::plural('DAY', $days), 'classes' => 'bg-amber-100 text-amber-700'],
            default => ['label' => 'ON TRACK', 'classes' => 'bg-brand-100 text-brand'],
        };
    }

    /** Progress percentage — real lesson completion (Sprint 5), 100 once completed. */
    public function progress(Enrollment $enrollment): int
    {
        return $enrollment->completionPercent();
    }
}

<?php

declare(strict_types=1);

namespace App\Livewire\Portal;

use App\Actions\Assignment\AssignCourse;
use App\Enums\CourseStatus;
use App\Enums\EmployeeStatus;
use App\Enums\EnrollmentStatus;
use App\Models\Course;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * A manager's team: their direct reports, each report's assigned courses, and the
 * ability to assign a course WITH A REASON — the manager screen from the design.
 *
 * The reason is the point: `AssignCourse` records it as the enrolment's
 * `rationale`, which the learner then sees as "why you were assigned this".
 *
 * Assignment is authorised by the `assign-course-to` gate — a line manager may
 * only assign to their own reports (L&D/admin reach anyone, but they use the
 * admin panel's org-wide flow, not this page).
 */
#[Layout('components.layouts.app')]
class Team extends Component
{
    public ?string $assigningToEmployeeId = null;

    public ?string $courseId = null;

    public string $reason = '';

    public ?string $dueDate = null;

    public function startAssign(string $employeeId): void
    {
        $this->reset('courseId', 'reason', 'dueDate');
        $this->assigningToEmployeeId = $employeeId;
    }

    public function cancelAssign(): void
    {
        $this->reset('assigningToEmployeeId', 'courseId', 'reason', 'dueDate');
    }

    public function submitAssign(): void
    {
        $this->validate([
            'courseId' => 'required',
            'reason' => 'required|string|max:500',
            'dueDate' => 'nullable|date',
        ]);

        $report = Employee::query()->findOrFail($this->assigningToEmployeeId);

        // The line: a manager can only assign to their own reports.
        if (! Gate::allows('assign-course-to', $report)) {
            $this->addError('courseId', 'You can only assign courses to your own team.');

            return;
        }

        $course = Course::query()->findOrFail($this->courseId);

        app(AssignCourse::class)->handle(
            employee: $report,
            course: $course,
            rationale: $this->reason,
            assignedBy: auth()->user(),
            dueAt: $this->dueDate ? Carbon::parse($this->dueDate) : null,
        );

        $this->cancelAssign();
        $this->dispatch('course-assigned');
    }

    public function render(): View
    {
        /** @var User $user */
        $user = auth()->user();
        $employee = $user->employee;

        /** @var Collection<int, Employee> $reports */
        $reports = $employee instanceof Employee
            ? $employee->reports()
                ->where('status', '!=', EmployeeStatus::Exited->value)
                ->with(['enrollments' => fn ($q) => $q->with('course')])
                ->orderBy('first_name')
                ->get()
            : new Collection;

        return view('livewire.portal.team', [
            'reports' => $reports,
            'courses' => Course::query()->where('status', CourseStatus::Published)->orderBy('title')->get(),
            'openStatuses' => [EnrollmentStatus::Assigned, EnrollmentStatus::InProgress, EnrollmentStatus::Requested],
        ]);
    }
}

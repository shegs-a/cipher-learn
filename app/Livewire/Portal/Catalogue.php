<?php

declare(strict_types=1);

namespace App\Livewire\Portal;

use App\Actions\Assignment\AssignCourse;
use App\Enums\CourseStatus;
use App\Models\Course;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The learner's course catalogue: every published course in their tenant, with
 * the ability to request access to one they were not assigned.
 *
 * A request is a pending enrolment (source=self, status=requested) awaiting a
 * manager/admin decision — it is NOT self-enrolment. The learner supplies a note
 * saying why; the approver later adds the assignment reason.
 *
 * Tenant context is set by BindCurrentTenant, so the course list and the
 * learner's own enrolments are tenant-scoped.
 */
#[Layout('components.layouts.app')]
class Catalogue extends Component
{
    /** The course a request is being composed for (its modal is open). */
    public ?string $requestingCourseId = null;

    public string $note = '';

    public function startRequest(string $courseId): void
    {
        $this->requestingCourseId = $courseId;
        $this->note = '';
    }

    public function cancelRequest(): void
    {
        $this->reset('requestingCourseId', 'note');
    }

    public function submitRequest(): void
    {
        $this->validate(['note' => 'required|string|max:500']);

        $employee = $this->employee();
        $course = Course::query()->findOrFail($this->requestingCourseId);

        if ($employee === null) {
            $this->addError('note', 'Your account is not linked to an employee record.');

            return;
        }

        app(AssignCourse::class)->request($employee, $course, $this->note);

        $this->reset('requestingCourseId', 'note');
        $this->dispatch('request-sent');
    }

    private function employee(): ?Employee
    {
        /** @var User $user */
        $user = auth()->user();

        return $user->employee;
    }

    public function render(): View
    {
        $employee = $this->employee();

        /** @var array<int, string> $enrolledCourseIds */
        $enrolledCourseIds = $employee instanceof Employee
            ? $employee->enrollments()->pluck('course_id')->all()
            : [];

        /** @var Collection<int, Course> $courses */
        $courses = Course::query()
            ->where('status', CourseStatus::Published)
            ->withCount('lessons')
            ->orderBy('title')
            ->get();

        return view('livewire.portal.catalogue', [
            'courses' => $courses,
            'enrolledCourseIds' => $enrolledCourseIds,
        ]);
    }
}

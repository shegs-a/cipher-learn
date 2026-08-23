<?php

declare(strict_types=1);

namespace App\Livewire\Portal;

use App\Actions\Learning\AssignLearningPath;
use App\Enums\EnrollmentSource;
use App\Models\Employee;
use App\Models\LearningPath;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The learner's learning paths: the curricula they're on (with progress) and the
 * ones they can join. Self-enrolment is **direct** — enrolling puts the path's
 * courses straight onto My Learning (via {@see AssignLearningPath}); each course
 * keeps its own record and certificate.
 */
#[Layout('components.layouts.app')]
class Paths extends Component
{
    /** Direct self-enrol into a path — its courses are assigned immediately. */
    public function enrol(string $pathId, AssignLearningPath $assignLearningPath): void
    {
        $employee = auth()->user()?->employee;
        if (! $employee instanceof Employee) {
            return;
        }

        // Tenant-scoped: a path id from another tenant simply won't resolve.
        $path = LearningPath::query()->find($pathId);
        if ($path === null) {
            return;
        }

        $assignLearningPath->handle(
            path: $path,
            employee: $employee,
            rationale: 'You enrolled yourself in this learning path.',
            source: EnrollmentSource::SelfEnrolled,
        );
    }

    public function render(): View
    {
        $employee = auth()->user()?->employee;

        $myPaths = $employee instanceof Employee
            ? $employee->pathEnrollments()->with('learningPath.courses')->get()
            : new Collection;

        $myPathIds = $myPaths->pluck('learning_path_id');

        // Paths the learner isn't on yet.
        $available = LearningPath::query()
            ->with('courses')
            ->whereNotIn('id', $myPathIds)
            ->has('courses') // don't offer empty paths
            ->orderBy('name')
            ->get();

        // The learner's course enrolments, keyed by course, for per-course status.
        $enrolmentsByCourse = $employee instanceof Employee
            ? $employee->enrollments()->get()->keyBy('course_id')
            : new Collection;

        return view('livewire.portal.paths', [
            'myPaths' => $myPaths,
            'available' => $available,
            'enrolmentsByCourse' => $enrolmentsByCourse,
        ]);
    }
}

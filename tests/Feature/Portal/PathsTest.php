<?php

declare(strict_types=1);

use App\Actions\Learning\AssignLearningPath;
use App\Enums\EnrollmentSource;
use App\Enums\EnrollmentStatus;
use App\Livewire\Portal\Paths;
use App\Models\Course;
use App\Models\Employee;
use App\Models\Enrollment;
use App\Models\LearningPath;
use App\Models\PathEnrollment;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(Tenancy::class)->set($this->tenant->id);

    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    app(Tenancy::class)->runFor($this->tenant, function () {
        $this->employee = Employee::factory()->create(['user_id' => $this->user->id]);
    });
});

function makePath(Tenant $tenant, string $name, int $courses = 2): LearningPath
{
    return app(Tenancy::class)->runFor($tenant, function () use ($name, $courses) {
        $path = LearningPath::factory()->create(['name' => $name]);
        $cs = collect(range(1, $courses))->map(fn () => Course::factory()->create());
        $path->courses()->attach($cs->mapWithKeys(fn ($c, $i) => [$c->id => ['position' => $i + 1]])->all());

        return $path->fresh();
    });
}

it('lists available paths and lets a learner self-enrol directly', function () {
    $path = makePath($this->tenant, 'Onboarding Path', 2);

    $this->actingAs($this->user);

    Livewire::test(Paths::class)
        ->assertSee('Onboarding Path')
        ->call('enrol', $path->id);

    app(Tenancy::class)->runFor($this->tenant, function () use ($path) {
        // A membership + one enrolment per course, marked self-enrolled.
        expect(PathEnrollment::where('employee_id', $this->employee->id)->where('learning_path_id', $path->id)->count())->toBe(1)
            ->and(Enrollment::where('employee_id', $this->employee->id)->count())->toBe(2)
            ->and(Enrollment::where('employee_id', $this->employee->id)->first()->source)->toBe(EnrollmentSource::SelfEnrolled);
    });
});

it("shows the learner's own paths with progress", function () {
    $path = makePath($this->tenant, 'My Curriculum', 2);
    app(Tenancy::class)->runFor($this->tenant, function () use ($path) {
        app(AssignLearningPath::class)->handle($path, $this->employee, 'Assigned');
        // Complete one of the two courses → 50%.
        Enrollment::where('employee_id', $this->employee->id)->first()
            ->update(['status' => EnrollmentStatus::Completed->value]);
    });

    $this->actingAs($this->user);

    Livewire::test(Paths::class)
        ->assertSee('My Curriculum')
        ->assertSee('50%');
});

it('does not show a path the learner is already on under available', function () {
    $path = makePath($this->tenant, 'Joined Path', 1);
    app(Tenancy::class)->runFor($this->tenant, fn () => app(AssignLearningPath::class)->handle($path, $this->employee, 'Assigned'));

    $this->actingAs($this->user);

    // "Joined Path" appears (under Your paths) but there are no Available paths left.
    Livewire::test(Paths::class)->assertDontSee('Available paths');
});

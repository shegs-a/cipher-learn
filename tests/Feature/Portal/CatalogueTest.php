<?php

declare(strict_types=1);

use App\Enums\CourseStatus;
use App\Enums\EnrollmentStatus;
use App\Livewire\Portal\Catalogue;
use App\Models\Course;
use App\Models\Employee;
use App\Models\Enrollment;
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
    $this->employee = null;
    app(Tenancy::class)->runFor($this->tenant, function () {
        $this->employee = Employee::factory()->create(['user_id' => $this->user->id]);
    });
});

it('requires authentication', function () {
    $this->get('/portal/catalogue')->assertRedirect('/login');
});

it('lists published courses and hides drafts', function () {
    app(Tenancy::class)->runFor($this->tenant, function () {
        Course::factory()->create(['title' => 'Published One', 'status' => CourseStatus::Published]);
        Course::factory()->create(['title' => 'A Draft', 'status' => CourseStatus::Draft]);
    });

    $this->actingAs($this->user);

    Livewire::test(Catalogue::class)
        ->assertSee('Published One')
        ->assertDontSee('A Draft');
});

it('marks a course the learner is already enrolled in as on their list', function () {
    app(Tenancy::class)->runFor($this->tenant, function () {
        $course = Course::factory()->create(['title' => 'Already Mine', 'status' => CourseStatus::Published]);
        Enrollment::factory()->create(['employee_id' => $this->employee->id, 'course_id' => $course->id]);
    });

    $this->actingAs($this->user);

    Livewire::test(Catalogue::class)
        ->assertSee('On your list')
        ->assertDontSee('Request access');
});

it('creates a pending request with the learner note', function () {
    $course = null;
    app(Tenancy::class)->runFor($this->tenant, function () use (&$course) {
        $course = Course::factory()->create(['title' => 'Wanted', 'status' => CourseStatus::Published]);
    });

    $this->actingAs($this->user);

    Livewire::test(Catalogue::class)
        ->call('startRequest', $course->id)
        ->set('note', 'Relevant to my next project.')
        ->call('submitRequest')
        ->assertHasNoErrors();

    app(Tenancy::class)->runFor($this->tenant, function () use ($course) {
        $enrollment = Enrollment::where('course_id', $course->id)->first();
        expect($enrollment)->not->toBeNull()
            ->and($enrollment->status)->toBe(EnrollmentStatus::Requested)
            ->and($enrollment->rationale)->toBe('Relevant to my next project.');
    });
});

it('requires a note on a request', function () {
    $course = null;
    app(Tenancy::class)->runFor($this->tenant, function () use (&$course) {
        $course = Course::factory()->create(['status' => CourseStatus::Published]);
    });

    $this->actingAs($this->user);

    Livewire::test(Catalogue::class)
        ->call('startRequest', $course->id)
        ->set('note', '')
        ->call('submitRequest')
        ->assertHasErrors('note');
});

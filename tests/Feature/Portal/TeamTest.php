<?php

declare(strict_types=1);

use App\Enums\CourseStatus;
use App\Enums\EnrollmentStatus;
use App\Livewire\Portal\Team;
use App\Models\Course;
use App\Models\Employee;
use App\Models\Enrollment;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(Tenancy::class)->set($this->tenant->id);
    app(RolesAndPermissionsSeeder::class)->run();

    $this->manager = User::factory()->create(['tenant_id' => $this->tenant->id]);
    app(Tenancy::class)->runFor($this->tenant, function () {
        $this->managerEmployee = Employee::factory()->create(['user_id' => $this->manager->id]);
        $this->manager->assignRole('Manager');
        $this->report = Employee::factory()->create(['first_name' => 'Kwame', 'manager_id' => $this->managerEmployee->id]);
        $this->course = Course::factory()->create(['title' => 'Consultative Selling', 'status' => CourseStatus::Published]);
    });

    $this->actingAs($this->manager);
});

it('requires authentication', function () {
    auth()->logout();
    $this->get('/portal/team')->assertRedirect('/login');
});

it('lists the manager\'s direct reports', function () {
    Livewire::test(Team::class)->assertSee('Kwame');
});

it('assigns a course to a report with a reason', function () {
    Livewire::test(Team::class)
        ->call('startAssign', $this->report->id)
        ->set('courseId', $this->course->id)
        ->set('reason', 'Deal conversion flagged in your Q2 review.')
        ->call('submitAssign')
        ->assertHasNoErrors();

    app(Tenancy::class)->runFor($this->tenant, function () {
        $enrollment = Enrollment::where('employee_id', $this->report->id)->first();
        expect($enrollment)->not->toBeNull()
            ->and($enrollment->status)->toBe(EnrollmentStatus::Assigned)
            ->and($enrollment->rationale)->toBe('Deal conversion flagged in your Q2 review.')
            ->and($enrollment->assigned_by_user_id)->toBe($this->manager->id);
    });
});

it('refuses to assign to someone who is not a direct report', function () {
    $stranger = null;
    app(Tenancy::class)->runFor($this->tenant, function () use (&$stranger) {
        $stranger = Employee::factory()->create(); // reports to nobody
    });

    Livewire::test(Team::class)
        ->call('startAssign', $stranger->id)
        ->set('courseId', $this->course->id)
        ->set('reason', 'Trying to overreach')
        ->call('submitAssign')
        ->assertHasErrors('courseId');

    app(Tenancy::class)->runFor($this->tenant, function () use ($stranger) {
        expect(Enrollment::where('employee_id', $stranger->id)->exists())->toBeFalse();
    });
});

it('requires a course and a reason', function () {
    Livewire::test(Team::class)
        ->call('startAssign', $this->report->id)
        ->set('courseId', '')
        ->set('reason', '')
        ->call('submitAssign')
        ->assertHasErrors(['courseId', 'reason']);
});

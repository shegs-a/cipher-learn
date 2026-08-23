<?php

declare(strict_types=1);

use App\Actions\Assignment\AssignCourse;
use App\Enums\EnrollmentStatus;
use App\Filament\Pages\Reports\EnrolmentsReportPage;
use App\Models\Course;
use App\Models\Employee;
use App\Models\Enrollment;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id]);

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    actingAs($this->admin);
    app(Tenancy::class)->set($this->tenant->id);

    app(RolesAndPermissionsSeeder::class)->run();
    $this->admin->assignRole('Tenant Admin');

    $this->employee = Employee::factory()->create();
    $this->course = Course::factory()->create(['title' => 'Data Protection']);
});

it('shows enrolments on the report and is hidden from a Content Administrator', function () {
    Enrollment::factory()->create(['employee_id' => $this->employee->id, 'course_id' => $this->course->id]);

    Livewire::test(EnrolmentsReportPage::class)->assertSee('Data Protection');

    $ca = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $ca->assignRole('Content Administrator');
    actingAs($ca);
    app(Tenancy::class)->set($this->tenant->id);
    expect(EnrolmentsReportPage::canAccess())->toBeFalse();
});

it('approves a learner request into an assignment from the report', function () {
    $request = app(AssignCourse::class)->request($this->employee, $this->course, 'Please, relevant to my role.');

    Livewire::test(EnrolmentsReportPage::class)
        ->callTableAction('approve', $request, data: [
            'rationale' => 'Approved — supports your development plan.',
        ])
        ->assertHasNoTableActionErrors();

    expect($request->fresh()->status)->toBe(EnrollmentStatus::Assigned)
        ->and($request->fresh()->rationale)->toBe('Approved — supports your development plan.');
});

it('rejects a learner request as cancelled from the report', function () {
    $request = app(AssignCourse::class)->request($this->employee, $this->course, 'I would like this.');

    Livewire::test(EnrolmentsReportPage::class)->callTableAction('reject', $request);

    expect($request->fresh()->status)->toBe(EnrollmentStatus::Cancelled);
});

it('filters the report only when Filter is applied', function () {
    $completed = Enrollment::factory()->create(['employee_id' => $this->employee->id, 'course_id' => $this->course->id, 'status' => EnrollmentStatus::Completed]);
    $assigned = Enrollment::factory()->create(['employee_id' => Employee::factory()->create()->id, 'course_id' => Course::factory()->create(['title' => 'Other Course'])->id, 'status' => EnrollmentStatus::Assigned]);

    Livewire::test(EnrolmentsReportPage::class)
        // Both visible unfiltered.
        ->assertCanSeeTableRecords([$completed, $assigned])
        // Apply a status filter (the browser defers this to the Filter button;
        // fillForm + applyFilters is the test equivalent).
        ->fillForm(['status' => EnrollmentStatus::Completed->value])
        ->call('applyFilters')
        ->assertCanSeeTableRecords([$completed])
        ->assertCanNotSeeTableRecords([$assigned]);
});

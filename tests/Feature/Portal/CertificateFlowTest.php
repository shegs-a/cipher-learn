<?php

declare(strict_types=1);

use App\Actions\Learning\CompleteCourse;
use App\Actions\Learning\IssueCertificate;
use App\Enums\CourseStatus;
use App\Enums\EnrollmentStatus;
use App\Livewire\Portal\Certificates as CertificatesPage;
use App\Models\Certificate;
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
    $this->tenant = Tenant::factory()->create(['name' => 'Acme Bank']);
    app(Tenancy::class)->set($this->tenant->id);

    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    app(Tenancy::class)->runFor($this->tenant, function () {
        $this->employee = Employee::factory()->create([
            'user_id' => $this->user->id,
            'first_name' => 'Ada',
            'last_name' => 'Obi',
        ]);
    });
});

/** A completed-ready enrolment for the beforeEach employee. */
function completedEnrolment(Tenant $tenant, Employee $employee, ?int $recertMonths = null): Enrollment
{
    return app(Tenancy::class)->runFor($tenant, function () use ($employee, $recertMonths) {
        $course = Course::factory()->create([
            'title' => 'AML Essentials',
            'status' => CourseStatus::Published,
            'recert_months' => $recertMonths,
        ]);

        return Enrollment::factory()->create([
            'employee_id' => $employee->id,
            'course_id' => $course->id,
            'status' => EnrollmentStatus::InProgress,
        ]);
    });
}

it('issues exactly one certificate on completion, idempotently', function () {
    $enrollment = completedEnrolment($this->tenant, $this->employee);

    app(Tenancy::class)->runFor($this->tenant, function () use ($enrollment) {
        $first = app(IssueCertificate::class)->handle($enrollment);
        $second = app(IssueCertificate::class)->handle($enrollment);

        expect($first->is($second))->toBeTrue()
            ->and(Certificate::where('enrollment_id', $enrollment->id)->count())->toBe(1)
            ->and($first->serial)->toStartWith('SL-')
            ->and($first->employee_id)->toBe($this->employee->id)
            ->and($first->expires_at)->toBeNull();
    });
});

it('sets a recertification expiry from the course recert months', function () {
    $enrollment = completedEnrolment($this->tenant, $this->employee, recertMonths: 12);

    app(Tenancy::class)->runFor($this->tenant, function () use ($enrollment) {
        $cert = app(IssueCertificate::class)->handle($enrollment);

        expect($cert->expires_at)->not->toBeNull()
            ->and($cert->expires_at->format('Y-m-d'))
            ->toBe($cert->issued_at->copy()->addMonths(12)->format('Y-m-d'));
    });
});

it('mints the certificate when a course is completed', function () {
    $enrollment = completedEnrolment($this->tenant, $this->employee);

    app(Tenancy::class)->runFor($this->tenant, function () use ($enrollment) {
        app(CompleteCourse::class)->handle($enrollment);

        expect($enrollment->fresh()->status)->toBe(EnrollmentStatus::Completed)
            ->and($enrollment->certificate()->exists())->toBeTrue();
    });
});

it('lists the learner\'s own certificates on the portal page', function () {
    $enrollment = completedEnrolment($this->tenant, $this->employee);
    app(Tenancy::class)->runFor($this->tenant, fn () => app(IssueCertificate::class)->handle($enrollment));

    $this->actingAs($this->user);

    Livewire::test(CertificatesPage::class)
        ->assertSee('AML Essentials')
        ->assertSee('SL-');
});

it('shows the learner their own printable certificate', function () {
    $enrollment = completedEnrolment($this->tenant, $this->employee);
    $cert = app(Tenancy::class)->runFor($this->tenant, fn () => app(IssueCertificate::class)->handle($enrollment));

    $this->actingAs($this->user)
        ->get(route('portal.certificate', $cert))
        ->assertOk()
        ->assertSee('Ada Obi')
        ->assertSee('AML Essentials')
        ->assertSee($cert->serial);
});

it('404s the printable certificate for another employee', function () {
    $otherCert = app(Tenancy::class)->runFor($this->tenant, function () {
        $other = Employee::factory()->create();
        $enrollment = completedEnrolment($this->tenant, $other);

        return app(IssueCertificate::class)->handle($enrollment);
    });

    $this->actingAs($this->user)
        ->get(route('portal.certificate', $otherCert))
        ->assertNotFound();
});

it('verifies a genuine certificate publicly, without login', function () {
    $enrollment = completedEnrolment($this->tenant, $this->employee);
    $cert = app(Tenancy::class)->runFor($this->tenant, fn () => app(IssueCertificate::class)->handle($enrollment));

    // No actingAs — a guest, no tenant bound.
    $this->get(route('verify.certificate', $cert->serial))
        ->assertOk()
        ->assertSee('Genuine certificate')
        ->assertSee('Ada Obi')
        ->assertSee('AML Essentials')
        ->assertSee('Acme Bank');
});

it('reports an unknown serial as not verifiable', function () {
    $this->get(route('verify.certificate', 'SL-XXXX-XXXX-XXXX'))
        ->assertOk()
        ->assertSee('No matching certificate');
});

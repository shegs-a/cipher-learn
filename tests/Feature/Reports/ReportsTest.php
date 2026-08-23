<?php

declare(strict_types=1);

use App\Enums\EnrollmentStatus;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\Employee;
use App\Models\Enrollment;
use App\Models\QuizAttempt;
use App\Models\Tenant;
use App\Support\Reports\CertificatesReport;
use App\Support\Reports\CompletionsReport;
use App\Support\Reports\OverdueReport;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(Tenancy::class)->set($this->tenant->id);
});

// ---- Completions ---------------------------------------------------------

it('lists only assessed outcomes with best score and attempt count', function () {
    app(Tenancy::class)->runFor($this->tenant, function () {
        $course = Course::factory()->create();
        $quiz = $course->quiz()->create(['title' => 'Assessment']);
        $emp = Employee::factory()->create();
        $completed = Enrollment::factory()->for($emp)->for($course)->create(['status' => EnrollmentStatus::Completed, 'completed_at' => now()]);
        // A fail then a pass → best score 90, attempts 2.
        QuizAttempt::factory()->create(['enrollment_id' => $completed->id, 'quiz_id' => $quiz->id, 'employee_id' => $emp->id, 'score' => 60, 'passed' => false, 'submitted_at' => now()]);
        QuizAttempt::factory()->create(['enrollment_id' => $completed->id, 'quiz_id' => $quiz->id, 'employee_id' => $emp->id, 'score' => 90, 'passed' => true, 'submitted_at' => now()]);

        // In-progress enrolment must NOT appear.
        Enrollment::factory()->for(Employee::factory())->for(Course::factory()->create())->create(['status' => EnrollmentStatus::InProgress]);
    });

    app(Tenancy::class)->runFor($this->tenant, function () {
        $report = new CompletionsReport;
        $records = $report->query([])->get();

        expect($records)->toHaveCount(1);
        $row = $report->row($records->first());
        expect($row['result'])->toBe('Completed')
            ->and($row['score'])->toBe('90%')
            ->and($row['attempts'])->toBe(2);
    });
});

it('completions are tenant-scoped', function () {
    $other = Tenant::factory()->create();
    app(Tenancy::class)->runFor($other, fn () => Enrollment::factory()->for(Employee::factory())->for(Course::factory())->create(['status' => EnrollmentStatus::Completed, 'completed_at' => now()]));

    app(Tenancy::class)->runFor($this->tenant, fn () => expect((new CompletionsReport)->query([])->count())->toBe(0));
});

// ---- Certificates --------------------------------------------------------

it('classifies certificate recertification status and filters by window', function () {
    app(Tenancy::class)->runFor($this->tenant, function () {
        foreach ([
            ['expires_at' => now()->addDays(30), 'want' => 'Recert due'],
            ['expires_at' => now()->addDays(200), 'want' => 'Valid'],
            ['expires_at' => now()->subDay(), 'want' => 'Expired'],
            ['expires_at' => null, 'want' => 'No expiry'],
        ] as $case) {
            $emp = Employee::factory()->create();
            $course = Course::factory()->create();
            $enr = Enrollment::factory()->for($emp)->for($course)->create(['status' => EnrollmentStatus::Completed]);
            Certificate::factory()->create(['employee_id' => $emp->id, 'course_id' => $course->id, 'enrollment_id' => $enr->id, 'issued_at' => now(), 'expires_at' => $case['expires_at']]);
        }
    });

    app(Tenancy::class)->runFor($this->tenant, function () {
        $report = new CertificatesReport;

        $statuses = $report->query([])->get()->map(fn ($c) => $report->row($c)['status'])->all();
        expect($statuses)->toContain('Recert due', 'Valid', 'Expired', 'No expiry');

        // "Expiring within 60 days" → only the 30-day cert.
        expect($report->query(['expiring_within' => '60'])->count())->toBe(1);
    });
});

// ---- Overdue -------------------------------------------------------------

it('returns only overdue open enrolments by default, with the manager', function () {
    app(Tenancy::class)->runFor($this->tenant, function () {
        $manager = Employee::factory()->create(['first_name' => 'Boss', 'last_name' => 'Lady']);
        $emp = Employee::factory()->create(['manager_id' => $manager->id]);
        $course = Course::factory()->create();
        // Overdue open.
        Enrollment::factory()->for($emp)->for($course)->create(['status' => EnrollmentStatus::Assigned, 'due_at' => now()->subDays(3)]);
        // Not overdue (future due).
        Enrollment::factory()->for($emp)->for(Course::factory()->create())->create(['status' => EnrollmentStatus::InProgress, 'due_at' => now()->addDays(5)]);
        // Overdue but completed → excluded (not open).
        Enrollment::factory()->for($emp)->for(Course::factory()->create())->create(['status' => EnrollmentStatus::Completed, 'due_at' => now()->subDays(3)]);
    });

    app(Tenancy::class)->runFor($this->tenant, function () {
        $report = new OverdueReport;
        $records = $report->query([])->get();

        expect($records)->toHaveCount(1);
        $row = $report->row($records->first());
        expect($row['manager'])->toBe('Boss Lady')
            ->and((int) $row['days_overdue'])->toBeGreaterThanOrEqual(2);
    });
});

it('the due-soon window returns upcoming, not overdue', function () {
    app(Tenancy::class)->runFor($this->tenant, function () {
        $emp = Employee::factory()->create();
        Enrollment::factory()->for($emp)->for(Course::factory()->create())->create(['status' => EnrollmentStatus::Assigned, 'due_at' => now()->subDays(3)]);   // overdue
        Enrollment::factory()->for($emp)->for(Course::factory()->create())->create(['status' => EnrollmentStatus::Assigned, 'due_at' => now()->addDays(5)]);   // due soon
    });

    app(Tenancy::class)->runFor($this->tenant, function () {
        expect((new OverdueReport)->query(['window' => 'due_7'])->count())->toBe(1)
            ->and((new OverdueReport)->query(['window' => 'overdue'])->count())->toBe(1);
    });
});

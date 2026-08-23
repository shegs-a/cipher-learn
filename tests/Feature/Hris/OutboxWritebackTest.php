<?php

declare(strict_types=1);

use App\Actions\Hris\ProcessOutbox;
use App\Actions\Learning\CompleteCourse;
use App\Enums\CourseStatus;
use App\Enums\EnrollmentStatus;
use App\Models\Course;
use App\Models\Employee;
use App\Models\Enrollment;
use App\Models\OutboxEvent;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\FailingWritebackAdapter;

uses(RefreshDatabase::class);

/**
 * Complete a course for a fresh employee on the given adapter, returning the
 * enrolment (which now has a pending training.completion outbox event).
 */
function completeOnTenant(Tenant $tenant, string $adapter = 'mock', ?string $externalId = 'EMP-5000'): Enrollment
{
    $tenant->update(['hris_adapter' => $adapter]);

    return app(Tenancy::class)->runFor($tenant, function () use ($externalId) {
        $employee = Employee::factory()->create(['external_id' => $externalId]);
        $course = Course::factory()->create(['title' => 'Consultative Selling', 'status' => CourseStatus::Published]);
        $enrollment = Enrollment::factory()->create([
            'employee_id' => $employee->id,
            'course_id' => $course->id,
            'status' => EnrollmentStatus::InProgress,
        ]);

        app(CompleteCourse::class)->handle($enrollment);

        return $enrollment->fresh();
    });
}

it('enqueues a training.completion outbox event on completion', function () {
    $tenant = Tenant::factory()->create();
    $enrollment = completeOnTenant($tenant);

    app(Tenancy::class)->runFor($tenant, function () use ($enrollment) {
        $event = OutboxEvent::where('type', 'training.completion')->first();

        expect($event)->not->toBeNull()
            ->and($event->status)->toBe('pending')
            ->and($event->payload['enrollment_id'])->toBe($enrollment->id)
            ->and($event->payload['employee_external_id'])->toBe('EMP-5000')
            ->and($event->payload['course_title'])->toBe('Consultative Selling')
            ->and($event->payload['certificate_serial'])->toStartWith('SL-');
    });
});

it('does not enqueue a second event when completion runs again', function () {
    $tenant = Tenant::factory()->create();
    $enrollment = completeOnTenant($tenant);

    app(Tenancy::class)->runFor($tenant, function () use ($enrollment) {
        // Force another completion pass (already completed → no-op, no dup).
        app(CompleteCourse::class)->handle($enrollment);

        expect(OutboxEvent::where('type', 'training.completion')->count())->toBe(1);
    });
});

it('processes the event through the mock adapter and marks it processed', function () {
    $tenant = Tenant::factory()->create();
    completeOnTenant($tenant, adapter: 'mock');

    // Sweep with no ambient tenant, like the real worker.
    app(Tenancy::class)->forget();
    $stats = app(ProcessOutbox::class)->handle();

    expect($stats['processed'])->toBe(1)
        ->and($stats['unsupported'])->toBe(0);

    app(Tenancy::class)->runFor($tenant, function () {
        $event = OutboxEvent::first();
        expect($event->status)->toBe('processed')
            ->and($event->processed_at)->not->toBeNull();
    });
});

it('parks an ExampleHR completion as unsupported, never retried', function () {
    $tenant = Tenant::factory()->create();
    completeOnTenant($tenant, adapter: 'example');

    app(Tenancy::class)->forget();
    $stats = app(ProcessOutbox::class)->handle();

    expect($stats['unsupported'])->toBe(1)
        ->and($stats['processed'])->toBe(0)
        ->and($stats['failed'])->toBe(0);

    app(Tenancy::class)->runFor($tenant, function () {
        $event = OutboxEvent::first();
        expect($event->status)->toBe('unsupported')
            ->and($event->processed_at)->not->toBeNull();
    });

    // A second sweep must not touch a terminal event.
    app(Tenancy::class)->forget();
    expect(app(ProcessOutbox::class)->handle())
        ->toBe(['processed' => 0, 'unsupported' => 0, 'failed' => 0, 'retried' => 0]);
});

it('marks completion unsupported when the employee has no HRIS id', function () {
    $tenant = Tenant::factory()->create();
    completeOnTenant($tenant, adapter: 'mock', externalId: null);

    app(Tenancy::class)->forget();
    $stats = app(ProcessOutbox::class)->handle();

    expect($stats['unsupported'])->toBe(1);

    app(Tenancy::class)->runFor($tenant, fn () => expect(OutboxEvent::first()->status)->toBe('unsupported'));
});

it('retries a transient failure, then fails after the attempt cap', function () {
    config()->set('hris.adapters.failing', FailingWritebackAdapter::class);

    $tenant = Tenant::factory()->create();
    completeOnTenant($tenant, adapter: 'failing');

    // First sweep: a transient error backs the event off (still pending, retried).
    app(Tenancy::class)->forget();
    expect(app(ProcessOutbox::class)->handle()['retried'])->toBe(1);

    app(Tenancy::class)->runFor($tenant, function () {
        $event = OutboxEvent::first();
        expect($event->status)->toBe('pending')
            ->and($event->attempts)->toBe(1)
            ->and($event->available_at->isFuture())->toBeTrue()
            ->and($event->last_error)->toContain('outage');

        // Push it to the brink and make it due again.
        $event->forceFill(['attempts' => 4, 'available_at' => Carbon::now()->subMinute()])->save();
    });

    // Next sweep: the 5th attempt exhausts the cap → failed.
    app(Tenancy::class)->forget();
    expect(app(ProcessOutbox::class)->handle()['failed'])->toBe(1);

    app(Tenancy::class)->runFor($tenant, fn () => expect(OutboxEvent::first()->status)->toBe('failed'));
});

it('processes each tenant through its own adapter in one sweep', function () {
    $mockTenant = Tenant::factory()->create();
    $exampleTenant = Tenant::factory()->create();
    completeOnTenant($mockTenant, adapter: 'mock');
    completeOnTenant($exampleTenant, adapter: 'example');

    app(Tenancy::class)->forget();
    $stats = app(ProcessOutbox::class)->handle();

    expect($stats['processed'])->toBe(1)   // the mock tenant
        ->and($stats['unsupported'])->toBe(1); // the ExampleHR tenant
});

it('drains the outbox from the console command', function () {
    $tenant = Tenant::factory()->create();
    completeOnTenant($tenant, adapter: 'mock');

    app(Tenancy::class)->forget();
    $this->artisan('outbox:work')
        ->assertSuccessful()
        ->expectsOutputToContain('1 processed');
});

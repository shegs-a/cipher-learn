<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrollments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('course_id')->constrained()->cascadeOnDelete();

            $table->string('source'); // manual | self | onboarding | rule | recommender

            // The product's differentiator, and NOT optional: every assignment
            // carries a human-readable reason and the data that triggered it.
            // These are the whole point — not metadata.
            $table->text('rationale');
            $table->json('evidence');

            // How the target course was chosen (set by the engine in Sprint 3/6):
            // rule | model | lexical | manual. Surfaced in the admin panel.
            $table->string('method')->nullable();

            // Learning records are never deleted. Cancellation is a status change:
            // assigned | in_progress | completed | failed | waived | cancelled.
            $table->string('status')->default('assigned');

            // Recertification cycle. Without this, the unique key below would pin
            // an employee to ONE enrolment per course for all time, and Sprint 5's
            // nightly recert job (which re-assigns the same course for a new cycle)
            // would either be rejected or clobber the prior record and destroy the
            // audit trail. Holds the appraisal cycle / issue period (e.g. "2027-H1");
            // 'initial' for the first, manual, self and onboarding enrolments.
            // NOT NULL on purpose: MySQL treats NULLs as distinct in a unique index,
            // which would silently defeat the duplicate guard.
            $table->string('cycle')->default('initial');

            // DATETIME (not TIMESTAMP) for event columns that can hold future dates,
            // so we clear the 2038 epoch ceiling — a long recertification due date
            // could otherwise overflow. See certificates.expires_at for the sharp end.
            $table->dateTime('due_at')->nullable();
            $table->foreignUlid('assigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();

            // The database prevents duplicate enrolments — not application logic.
            // Scoped by cycle so re-running the same cycle is idempotent (Sprint 3)
            // while a new cycle can legitimately re-enrol the same employee.
            $table->unique(['tenant_id', 'employee_id', 'course_id', 'cycle']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollments');
    }
};

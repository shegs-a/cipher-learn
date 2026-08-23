<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks that an employee is on a learning path. A **linkage record only** — it says
 * "this person is doing this curriculum", which powers the learner's paths list and
 * path progress. It is deliberately NOT a status machine and NOT where a
 * certificate attaches: a path assignment fans out to a normal per-course enrolment
 * for each course (those keep the real records/progress/write-back), and path-level
 * certificates are a later sprint.
 *
 * Cycle-scoped like enrolments, so a future recertification can re-enrol onto the
 * same path without clobbering history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('path_enrollments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('learning_path_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('employee_id')->constrained()->cascadeOnDelete();

            $table->string('source')->default('manual'); // manual | self
            $table->foreignUlid('assigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cycle')->default('initial');

            $table->timestamps();

            // One active membership per employee per path per cycle. Explicit short
            // index name — the auto-generated one exceeds MySQL's 64-char limit.
            $table->unique(['tenant_id', 'learning_path_id', 'employee_id', 'cycle'], 'path_enrollments_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('path_enrollments');
    }
};

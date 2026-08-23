<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificates', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('enrollment_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('course_id')->constrained()->cascadeOnDelete();

            // Public verification key — resolvable without login (Sprint 5), so
            // it is globally unique, not per-tenant.
            $table->string('serial')->unique();
            // DATETIME, not TIMESTAMP: a recertification expiry is the one date
            // that legitimately runs far into the future, and TIMESTAMP's 32-bit
            // epoch ceiling (2038-01-19) would truncate long-dated certificates.
            $table->dateTime('issued_at');
            $table->dateTime('expires_at')->nullable(); // recertification deadline

            $table->timestamps();

            // One certificate per enrolment.
            $table->unique('enrollment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificates');
    }
};

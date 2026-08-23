<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();

            // Stable identifier from the HRIS; the join key for sync + write-back.
            $table->string('external_id')->nullable();

            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->nullable();
            $table->string('department')->nullable();
            $table->string('job_title')->nullable();
            $table->string('location')->nullable();

            // Org hierarchy for the manager view (Sprint 5). Self-referential.
            $table->foreignUlid('manager_id')->nullable()->constrained('employees')->nullOnDelete();

            // Leavers are marked exited, never deleted — their learning history
            // and open enrolments are preserved (cancelled as `waived`).
            $table->string('status')->default('active'); // active | exited

            $table->timestamps();

            $table->unique(['tenant_id', 'external_id']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};

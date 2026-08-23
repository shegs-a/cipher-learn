<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();

            // employee_sync | assignment_sweep | reconciliation | recertification
            $table->string('type');
            $table->string('status')->default('running'); // running | completed | failed
            // Dry-run sweeps compute + display outcomes, then roll back (Sprint 3).
            $table->boolean('dry_run')->default(false);
            // Per-run statistics (counts, skipped, errors) for the run-history UI.
            $table->json('stats')->nullable();
            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_runs');
    }
};

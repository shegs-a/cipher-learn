<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_leaves', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('employee_id')->constrained()->cascadeOnDelete();

            // The HRIS's id for this leave record — the upsert key, so a re-sync
            // updates rather than duplicates, and a cancelled record can be found
            // and removed.
            $table->string('external_id');
            $table->string('leave_type')->nullable();

            // Calendar dates (no time/zone): evaluated in the tenant's timezone.
            $table->date('starts_on');
            $table->date('ends_on');

            // The HRIS's authoritative "on leave now" verdict, when it has one.
            $table->boolean('is_current')->nullable();
            $table->dateTime('synced_at');
            $table->timestamps();

            $table->unique(['tenant_id', 'employee_id', 'external_id']);
            $table->index(['tenant_id', 'employee_id', 'starts_on', 'ends_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_leaves');
    }
};

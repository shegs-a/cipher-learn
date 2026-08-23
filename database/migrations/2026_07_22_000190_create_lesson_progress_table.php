<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_progress', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('enrollment_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('lesson_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('not_started'); // not_started | in_progress | completed
            $table->unsignedInteger('seconds_spent')->default(0);
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();

            // One progress row per lesson per enrolment.
            $table->unique(['enrollment_id', 'lesson_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_progress');
    }
};

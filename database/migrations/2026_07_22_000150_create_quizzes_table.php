<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quizzes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('course_id')->constrained()->cascadeOnDelete();
            $table->string('title')->default('Assessment');
            // Fall back to the course pass_mark / max_attempts when null, so a
            // quiz can override per-assessment without duplicating config.
            $table->unsignedTinyInteger('pass_mark')->nullable();
            $table->unsignedTinyInteger('max_attempts')->nullable();
            $table->timestamps();

            $table->index('course_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quizzes');
    }
};

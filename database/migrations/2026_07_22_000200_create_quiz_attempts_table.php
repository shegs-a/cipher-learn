<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quiz_attempts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('enrollment_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('quiz_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('employee_id')->constrained()->cascadeOnDelete();

            $table->unsignedTinyInteger('score')->nullable(); // percent, set on submission
            $table->boolean('passed')->default(false);
            // The learner's submitted answers only — never the correct keys.
            $table->json('answers')->nullable();

            $table->dateTime('started_at')->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->timestamps();

            $table->index(['enrollment_id', 'passed']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_attempts');
    }
};

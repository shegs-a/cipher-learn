<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('questions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('quiz_id')->constrained()->cascadeOnDelete();
            $table->text('prompt');
            $table->string('type')->default('single'); // single | multiple | boolean

            // Options presented to the learner: [{ "key": "a", "label": "..." }].
            $table->json('options');
            // The correct key(s). This column must NEVER be serialised into any
            // API response or rendered payload — grading is server-side only.
            // The Question model marks it hidden and casts it away from arrays.
            $table->json('correct_keys');

            $table->unsignedTinyInteger('points')->default(1);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['quiz_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('questions');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learning_paths', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('learning_path_course', function (Blueprint $table) {
            // Pure join table: no surrogate key. The (learning_path_id, course_id)
            // pair is the composite PRIMARY key — it's the natural clustered index
            // and enforces one row per course per path. A ULID surrogate would be
            // dead weight here AND would break belongsToMany::attach(), which
            // inserts pivot rows without an id (HasUlids only stamps model rows,
            // not pivot rows), leaving a NOT NULL char(26) PK with no value.
            $table->foreignUlid('learning_path_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('course_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);

            $table->primary(['learning_path_id', 'course_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learning_path_course');
        Schema::dropIfExists('learning_paths');
    }
};

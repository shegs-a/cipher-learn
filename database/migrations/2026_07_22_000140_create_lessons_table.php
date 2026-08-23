<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lessons', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('course_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            // Text-first content — no autoplay media (learners are on metered
            // mobile data). Media, if any, is referenced by size.
            $table->longText('content')->nullable();
            $table->unsignedInteger('position')->default(0);
            // Shown per-module and summed to a course total in the learner UI
            // so users can see the data cost before downloading.
            $table->unsignedBigInteger('file_size_bytes')->default(0);
            $table->unsignedSmallInteger('estimated_minutes')->nullable();
            $table->timestamps();

            $table->index(['course_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lessons');
    }
};

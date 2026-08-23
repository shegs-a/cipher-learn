<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('slug');
            $table->text('summary')->nullable();
            // Tags drive lexical fallback matching in the recommender (Sprint 6).
            $table->json('tags')->nullable();

            $table->unsignedTinyInteger('pass_mark')->default(70); // percent
            // null = unlimited attempts; null recert = no expiry.
            $table->unsignedTinyInteger('max_attempts')->nullable();
            $table->unsignedSmallInteger('recert_months')->nullable();
            $table->unsignedSmallInteger('estimated_minutes')->nullable();

            $table->string('status')->default('draft'); // draft | published | archived
            $table->timestamps();

            $table->unique(['tenant_id', 'slug']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courses');
    }
};

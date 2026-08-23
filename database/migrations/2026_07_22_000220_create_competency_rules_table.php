<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competency_rules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');

            // Keywords matched against KPI/OKR titles (e.g. ["collections",
            // "debt recovery"]). The unit of analysis is attainment = actual vs
            // target, so the threshold is a percentage of target at/under which
            // the gap triggers an assignment (default 70%).
            $table->json('keywords');
            $table->decimal('attainment_threshold', 5, 2)->default(70);

            $table->foreignUlid('target_course_id')->constrained('courses')->cascadeOnDelete();
            $table->unsignedSmallInteger('due_days')->default(30);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competency_rules');
    }
};

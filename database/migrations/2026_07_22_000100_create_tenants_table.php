<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            // ULID primary keys throughout (see create_users_table for the full
            // rationale): non-sequential so row counts don't leak across tenants,
            // and collision-free for a future platform merge.
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            // Per-tenant HRIS adapter selection + config lives here (Sprint 2).
            // Kept as JSON so adapter settings can evolve without a migration.
            $table->string('hris_adapter')->default('mock');
            $table->json('settings')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};

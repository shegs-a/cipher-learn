<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('type'); // e.g. training.completion
            $table->json('payload');

            // Transactional outbox (Sprint 5). ExampleHR has no training-completion
            // endpoint yet, so events for it are parked as `unsupported` (never
            // retried, never failed) and replayed the day the endpoint ships.
            // pending | processing | processed | failed | unsupported
            $table->string('status')->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->dateTime('available_at')->nullable();
            $table->dateTime('processed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['status', 'available_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_events');
    }
};

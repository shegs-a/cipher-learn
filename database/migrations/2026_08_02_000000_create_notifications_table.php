<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel's `database` notification channel table — hand-written rather than the
 * framework stub because our notifiable (`users`) uses a **ULID** primary key, so
 * `notifiable_id` must be `char(26)` (via `ulidMorphs`), not the default.
 *
 * Notifications belong to a User (which resolves the tenant), so there is no
 * `tenant_id` here — cross-tenant isolation is through the user the notification
 * targets. The notification `id` is a UUID, as Laravel's DatabaseNotification
 * model expects.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->ulidMorphs('notifiable'); // notifiable_type + char(26) notifiable_id + index
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};

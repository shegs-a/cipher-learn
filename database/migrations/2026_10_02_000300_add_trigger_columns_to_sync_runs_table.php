<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sync_runs', function (Blueprint $table) {
            // scheduled | manual. Existing rows are scheduled-or-CLI runs.
            $table->string('trigger')->default('scheduled')->after('type');
            $table->foreignUlid('triggered_by_user_id')->nullable()->after('trigger')
                ->constrained('users')->nullOnDelete();
            // Scheduled leave syncs only: "2026-10-02@06:00" — the tenant-local
            // date and slot this run satisfied, so a slot is never run twice.
            $table->string('slot')->nullable()->after('triggered_by_user_id');

            $table->index(['tenant_id', 'type', 'slot']);
        });
    }

    public function down(): void
    {
        Schema::table('sync_runs', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'type', 'slot']);
            $table->dropConstrainedForeignId('triggered_by_user_id');
            $table->dropColumn(['trigger', 'slot']);
        });
    }
};

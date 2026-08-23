<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the last overdue reminder went out for an enrolment, so the reminder
 * command nudges on a cadence (e.g. weekly) instead of every run — a learner
 * shouldn't be emailed about the same overdue course every time the scheduler
 * ticks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {
            $table->dateTime('reminded_at')->nullable()->after('completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropColumn('reminded_at');
        });
    }
};

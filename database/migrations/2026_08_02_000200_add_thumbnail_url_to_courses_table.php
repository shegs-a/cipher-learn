<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A course thumbnail. Stored as a URL (a path in the S3/MinIO bucket, or any
 * external image) rather than a binary, keeping the DB light. Nullable — the
 * learner dashboard reserves the space and shows a branded placeholder when a
 * course has no thumbnail set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->string('thumbnail_url')->nullable()->after('summary');
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn('thumbnail_url');
        });
    }
};

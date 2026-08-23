<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            // Links an HRIS-synced person to the login identity that represents
            // them. This is the load-bearing edge for RBAC: roles attach to the
            // `User`, and a logged-in learner resolves through here to their
            // `Employee` to see their own enrolments.
            //
            // NULLABLE on purpose: the employee exists first — the sync creates
            // them long before anyone provisions or logs in as them. SSO/JIT
            // (later) fills this in by matching email / external id.
            //
            // UNIQUE so one user maps to at most one employee. MySQL allows many
            // NULLs in a unique index, so the (large) pool of not-yet-provisioned
            // employees does not collide.
            $table->foreignUlid('user_id')->nullable()->after('tenant_id')
                ->constrained()->nullOnDelete();

            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropUnique(['user_id']);
            $table->dropConstrainedForeignId('user_id');
        });
    }
};

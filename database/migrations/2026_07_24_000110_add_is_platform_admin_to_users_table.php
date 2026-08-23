<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // The cross-tenant platform operator (us, running the platform).
            // Deliberately NOT a role: spatie roles are team-scoped, so a role
            // grants nothing outside its tenant, and a cross-tenant operator
            // cannot be expressed that way. Kept out of the role system entirely
            // so a Tenant Admin can never assign it. Enforced by a Gate::before()
            // bypass (see AuthServiceProvider). Off by default; set by hand.
            $table->boolean('is_platform_admin')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_platform_admin');
        });
    }
};

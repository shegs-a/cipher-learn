<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // `users` are the admin/L&D operators of the Filament panel. Learners
            // are `employees` (synced from the HRIS) and authenticate separately
            // in Sprint 4.
            $table->foreignUlid('tenant_id')->nullable()->after('id')->constrained()->cascadeOnDelete();

            // SSO-ready without committing to a provider yet. Local password login
            // works today; OIDC/SAML slots in by populating auth_provider +
            // external_id and leaving password null — no schema rework needed.
            $table->string('auth_provider')->default('local')->after('password');
            $table->string('external_id')->nullable()->after('auth_provider');
            $table->boolean('is_active')->default(true)->after('external_id');

            $table->unique(['tenant_id', 'external_id']);
        });

        // SSO users have no local password.
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'external_id']);
            $table->dropConstrainedForeignId('tenant_id');
            $table->dropColumn(['auth_provider', 'external_id', 'is_active']);
        });
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The tamper-evident audit trail: who did what, to what, when, and from where.
 *
 * Append-only and hash-chained **per tenant**. Every row stores a `sequence`
 * (gapless within a tenant) and a `hash = sha256(previous_hash ‖ canonical(row))`.
 * Any edit, deletion or insertion breaks the chain, which `audit:verify` detects.
 * There is deliberately **no `updated_at`** — rows never change (the model also
 * blocks updates/deletes at the application layer). DB-level WORM triggers are a
 * V2 hardening.
 *
 * `tenant_id` is nullable so genuinely tenant-less security events (e.g. a failed
 * login for an unknown email) can still be recorded, in their own null-tenant chain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->nullable()->constrained()->nullOnDelete();

            // Gapless per-tenant position — the chain order (and deletion detector).
            $table->unsignedBigInteger('sequence');

            $table->string('event'); // e.g. enrollment.updated, auth.login, report.exported

            // What was acted on (nullable — some events aren't about one record).
            $table->string('auditable_type')->nullable();
            $table->char('auditable_id', 26)->nullable();

            // Who did it (nullable actor = system/console). Denormalised label/roles
            // so the trail stands alone even if the user is later renamed or removed.
            $table->string('actor_type')->nullable();
            $table->char('actor_id', 26)->nullable();
            $table->string('actor_label')->nullable();
            // JSON stored as text (not the MySQL `json` type): the `json` type
            // normalises/reorders object keys on storage, which would change the
            // bytes the hash is computed over and break verification. Text stores
            // the value verbatim, so the chain round-trips exactly.
            $table->longText('actor_roles')->nullable();

            // The change, and the request context.
            $table->longText('old_values')->nullable();
            $table->longText('new_values')->nullable();
            $table->longText('context')->nullable(); // ip, user_agent, request_id, url, method

            // The tamper-evident chain.
            $table->char('previous_hash', 64)->nullable();
            $table->char('hash', 64);

            // Immutable creation time — no updated_at (rows never change).
            $table->timestamp('created_at')->nullable();

            $table->unique(['tenant_id', 'sequence']); // gapless, race-safe
            $table->index(['tenant_id', 'sequence']);
            $table->index(['auditable_type', 'auditable_id']);
            $table->index('event');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};

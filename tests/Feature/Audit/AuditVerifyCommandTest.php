<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Support\Audit\Auditor;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    // A short, known-good chain to verify against.
    app(Tenancy::class)->runFor($this->tenant, function () {
        $auditor = app(Auditor::class);
        $auditor->log('one');
        $auditor->log('two');
        $auditor->log('three');
    });
});

it('passes a verify on an intact chain', function () {
    $this->artisan('audit:verify', ['--tenant' => $this->tenant->id])
        ->expectsOutputToContain('intact')
        ->assertSuccessful();
});

it('sweeps every chain with --all', function () {
    $other = Tenant::factory()->create();
    app(Tenancy::class)->runFor($other, fn () => app(Auditor::class)->log('solo'));

    $this->artisan('audit:verify', ['--all' => true])
        ->assertSuccessful();
});

it('fails when a row is tampered in place', function () {
    // Mutate a row directly in the DB, bypassing the append-only model guard.
    DB::table('audit_logs')
        ->where('tenant_id', $this->tenant->id)
        ->where('sequence', 2)
        ->update(['event' => 'tampered']);

    $this->artisan('audit:verify', ['--tenant' => $this->tenant->id])
        ->expectsOutputToContain('content tampered')
        ->assertFailed();
});

it('fails when a row is deleted from the middle of the chain', function () {
    DB::table('audit_logs')
        ->where('tenant_id', $this->tenant->id)
        ->where('sequence', 2)
        ->delete();

    $this->artisan('audit:verify', ['--tenant' => $this->tenant->id])
        ->expectsOutputToContain('sequence gap')
        ->assertFailed();
});

it('fails when the genesis row is deleted', function () {
    DB::table('audit_logs')
        ->where('tenant_id', $this->tenant->id)
        ->where('sequence', 1)
        ->delete();

    // Now the first remaining row is sequence 2 — the sweep expects 1.
    $this->artisan('audit:verify', ['--tenant' => $this->tenant->id])
        ->assertFailed();
});

it('reports nothing to verify on an empty trail', function () {
    AuditLog::withoutGlobalScopes()->getQuery()->delete();

    $this->artisan('audit:verify', ['--all' => true])
        ->expectsOutputToContain('nothing to verify')
        ->assertSuccessful();
});

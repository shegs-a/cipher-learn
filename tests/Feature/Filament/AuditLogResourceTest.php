<?php

declare(strict_types=1);

use App\Filament\Resources\AuditLogResource;
use App\Filament\Resources\AuditLogResource\Pages\ListAuditLogs;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit\Auditor;
use App\Support\Tenancy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id]);

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    actingAs($this->admin);
    app(Tenancy::class)->set($this->tenant->id);

    app(RolesAndPermissionsSeeder::class)->run();
});

it('is read-only — no create, edit or delete', function () {
    expect(AuditLogResource::canCreate())->toBeFalse();
    expect(AuditLogResource::canEdit(new AuditLog))->toBeFalse();
    expect(AuditLogResource::canDelete(new AuditLog))->toBeFalse();
});

it('is visible only to holders of audit.view (Tenant Admin), not to an author', function () {
    $this->admin->assignRole('Content Administrator');
    expect(AuditLogResource::canViewAny())->toBeFalse();

    $this->admin->syncRoles(['Tenant Admin']);
    expect(AuditLogResource::canViewAny())->toBeTrue();
});

it('lists this tenant audit entries for an admin', function () {
    $this->admin->assignRole('Tenant Admin');
    app(Tenancy::class)->runFor($this->tenant, function () {
        app(Auditor::class)->log('test.one');
        app(Auditor::class)->log('test.two');
    });

    Livewire::test(ListAuditLogs::class)
        ->assertCanSeeTableRecords(AuditLog::all())
        ->assertCountTableRecords(2);
});

it('surfaces per-row integrity — a tampered row reads as invalid', function () {
    $this->admin->assignRole('Tenant Admin');
    $entry = app(Tenancy::class)->runFor($this->tenant, fn () => app(Auditor::class)->log('test.event'));

    expect($entry->hasValidHash())->toBeTrue();

    // Tamper directly in the DB, bypassing the append-only guard.
    DB::table('audit_logs')
        ->where('id', $entry->id)
        ->update(['event' => 'silently-changed']);

    expect($entry->fresh()->hasValidHash())->toBeFalse();
});

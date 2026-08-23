<?php

declare(strict_types=1);

use App\Filament\Resources\SyncRunResource;
use App\Filament\Resources\SyncRunResource\Pages\ListSyncRuns;
use App\Models\SyncRun;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
    $this->admin->assignRole('Tenant Admin');
});

it('lists past sync runs read-only', function () {
    $run = SyncRun::create([
        'type' => 'employee_sync',
        'status' => 'completed',
        'stats' => ['created' => 45, 'updated' => 0, 'unchanged' => 0, 'exited' => 0, 'errors' => 0],
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
    ]);

    Livewire::test(ListSyncRuns::class)->assertCanSeeTableRecords([$run]);

    // Audit trail only — nothing here may be created, edited or deleted.
    expect(SyncRunResource::canCreate())->toBeFalse()
        ->and(SyncRunResource::canEdit($run))->toBeFalse()
        ->and(SyncRunResource::canDelete($run))->toBeFalse();
});

it('scopes the history to the current tenant', function () {
    SyncRun::create([
        'type' => 'employee_sync',
        'status' => 'completed',
        'started_at' => now(),
        'finished_at' => now(),
    ]);

    $otherTenant = Tenant::factory()->create();
    app(Tenancy::class)->runFor($otherTenant, function () {
        SyncRun::create([
            'type' => 'employee_sync',
            'status' => 'completed',
            'started_at' => now(),
            'finished_at' => now(),
        ]);
    });

    Livewire::test(ListSyncRuns::class)->assertCountTableRecords(1);
});

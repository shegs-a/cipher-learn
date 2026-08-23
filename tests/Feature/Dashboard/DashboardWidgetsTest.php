<?php

declare(strict_types=1);

use App\Filament\Widgets\AdminOverviewStats;
use App\Filament\Widgets\RecentSyncRuns;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

// actingWithRole() is a shared test helper (see tests/Feature/Rbac/PanelAccessTest.php):
// creates a user in the tenant's team context with roles seeded, and acts as them.

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
});

it('shows the overview stats to a reporting role', function () {
    actingWithRole($this->tenant, 'Tenant Admin');
    expect(AdminOverviewStats::canView())->toBeTrue();
});

it('hides the overview stats and sync-runs from a content administrator', function () {
    actingWithRole($this->tenant, 'Content Administrator');

    expect(AdminOverviewStats::canView())->toBeFalse()
        ->and(RecentSyncRuns::canView())->toBeFalse();
});

it('shows the overview to an L&D manager, and sync-runs via employees.view', function () {
    actingWithRole($this->tenant, 'L&D Manager');

    expect(AdminOverviewStats::canView())->toBeTrue()   // reports.view
        ->and(RecentSyncRuns::canView())->toBeTrue();   // employees.view
});

it('renders the admin dashboard without error for an admin', function () {
    $admin = actingWithRole($this->tenant, 'Tenant Admin');

    $this->actingAs($admin)->get('/admin')->assertSuccessful();
});

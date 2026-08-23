<?php

declare(strict_types=1);

use App\Filament\Pages\Reports\CertificatesReportPage;
use App\Filament\Pages\Reports\CompletionsReportPage;
use App\Models\Course;
use App\Models\Employee;
use App\Models\Enrollment;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
});

/** Sign in as a user holding $role in the tenant's team context (roles seeded). */
function reportsActingAs(Tenant $tenant, string $role): void
{
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    app(Tenancy::class)->set($tenant->id);
    app(RolesAndPermissionsSeeder::class)->run();
    $user->assignRole($role);
    test()->actingAs($user);
}

it('streams a CSV export to a reports.view user', function () {
    reportsActingAs($this->tenant, 'Tenant Admin');
    app(Tenancy::class)->runFor($this->tenant, fn () => Enrollment::factory()
        ->for(Employee::factory()->create(['first_name' => 'Ada', 'last_name' => 'Obi']))
        ->for(Course::factory()->create(['title' => 'Consultative Selling']))
        ->create());

    $response = $this->get(route('reports.export', ['key' => 'enrolments']));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/csv');
    expect($response->streamedContent())
        ->toContain('Learner,Department,Course')
        ->toContain('Ada Obi')
        ->toContain('Consultative Selling');
});

it('forbids the export for a user without reports.view', function () {
    reportsActingAs($this->tenant, 'Content Administrator');

    $this->get(route('reports.export', ['key' => 'enrolments']))->assertForbidden();
});

it('404s an unknown report key', function () {
    reportsActingAs($this->tenant, 'Tenant Admin');

    $this->get(route('reports.export', ['key' => 'nonsense']))->assertNotFound();
});

it('scopes the export to the acting tenant', function () {
    // Another tenant's enrolment must never appear in this admin's export.
    $other = Tenant::factory()->create();
    app(Tenancy::class)->runFor($other, fn () => Enrollment::factory()
        ->for(Employee::factory()->create(['first_name' => 'Secret', 'last_name' => 'Person']))
        ->for(Course::factory()->create(['title' => 'Secret Course']))
        ->create());

    reportsActingAs($this->tenant, 'Tenant Admin');
    $csv = $this->get(route('reports.export', ['key' => 'enrolments']))->streamedContent();

    expect($csv)->not->toContain('Secret Course')
        ->and($csv)->not->toContain('Secret Person');
});

it('shows report pages to a reports.view role and hides them from a content admin', function () {
    reportsActingAs($this->tenant, 'Tenant Admin');
    expect(CompletionsReportPage::canAccess())->toBeTrue()
        ->and(CertificatesReportPage::canAccess())->toBeTrue();

    reportsActingAs($this->tenant, 'Content Administrator');
    expect(CompletionsReportPage::canAccess())->toBeFalse();
});

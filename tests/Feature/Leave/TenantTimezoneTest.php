<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('stores an IANA timezone on the tenant', function () {
    $tenant = Tenant::factory()->create(['timezone' => 'Africa/Lagos']);

    expect($tenant->fresh()->timezone)->toBe('Africa/Lagos');
});

it('rejects an invalid timezone instead of silently scheduling syncs at the wrong hour', function () {
    expect(fn () => Tenant::factory()->create(['timezone' => 'Mars/Olympus']))
        ->toThrow(InvalidArgumentException::class, 'Invalid timezone');

    $tenant = Tenant::factory()->create();

    expect(fn () => $tenant->update(['timezone' => 'WAT']))->toThrow(InvalidArgumentException::class);
});

it('defaults existing and new tenants to UTC until a timezone is set', function () {
    $tenant = Tenant::query()->create(['name' => 'Legacy', 'slug' => 'legacy']);

    expect($tenant->fresh()->timezone)->toBe('UTC');
});

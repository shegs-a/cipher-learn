<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('stores passwords hashed, never in plaintext', function () {
    $tenant = Tenant::factory()->create();

    // The `hashed` cast on User hashes this on assignment.
    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
        'password' => 'plain-text-secret',
    ]);

    $stored = $user->fresh()->password;

    expect($stored)->not->toBe('plain-text-secret')      // never plaintext
        ->and(Hash::check('plain-text-secret', $stored))->toBeTrue() // verifies
        ->and($stored)->toStartWith('$2y$');              // bcrypt hash
});

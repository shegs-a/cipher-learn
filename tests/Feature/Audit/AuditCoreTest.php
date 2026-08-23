<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit\Auditor;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(Tenancy::class)->set($this->tenant->id);
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);

    // These tests exercise the chain mechanics on an empty trail, so clear any
    // audit rows the setup itself produced (e.g. the User's own `user.created`).
    DB::table('audit_logs')->delete();
});

it('appends an entry with actor, context and a genesis hash', function () {
    $this->actingAs($this->user);

    $entry = app(Auditor::class)->log('test.event', newValues: ['a' => 1]);

    expect($entry->sequence)->toBe(1)
        ->and($entry->previous_hash)->toBeNull()
        ->and($entry->actor_id)->toBe((string) $this->user->getKey())
        ->and($entry->actor_label)->toBe($this->user->name)
        ->and($entry->hasValidHash())->toBeTrue();
});

it('chains each entry onto the previous one', function () {
    app(Tenancy::class)->runFor($this->tenant, function () {
        $auditor = app(Auditor::class);
        $first = $auditor->log('one');
        $second = $auditor->log('two');
        $third = $auditor->log('three');

        expect($second->sequence)->toBe(2)
            ->and($second->previous_hash)->toBe($first->hash)
            ->and($third->previous_hash)->toBe($second->hash)
            ->and($first->hasValidHash() && $second->hasValidHash() && $third->hasValidHash())->toBeTrue();
    });
});

it('is append-only — updates and deletes throw', function () {
    $entry = app(Tenancy::class)->runFor($this->tenant, fn () => app(Auditor::class)->log('locked'));

    expect(fn () => $entry->update(['event' => 'tampered']))->toThrow(RuntimeException::class);
    expect(fn () => $entry->delete())->toThrow(RuntimeException::class);
});

it('detects tampering — a mutated row fails its hash', function () {
    $entry = app(Tenancy::class)->runFor($this->tenant, fn () => app(Auditor::class)->log('original', newValues: ['x' => 1]));

    // Mutate the row directly in the DB, bypassing the model's append-only guard.
    DB::table('audit_logs')->where('id', $entry->id)->update(['event' => 'changed-behind-our-back']);

    expect($entry->fresh()->hasValidHash())->toBeFalse();
});

it('keeps a separate, independent chain per tenant', function () {
    $other = Tenant::factory()->create();

    app(Tenancy::class)->runFor($this->tenant, fn () => app(Auditor::class)->log('a1'));
    app(Tenancy::class)->runFor($other, fn () => app(Auditor::class)->log('b1'));
    $a2 = app(Tenancy::class)->runFor($this->tenant, fn () => app(Auditor::class)->log('a2'));

    expect($a2->sequence)->toBe(2) // this tenant's own count, unaffected by the other
        ->and(AuditLog::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count())->toBe(2)
        ->and(AuditLog::withoutGlobalScopes()->where('tenant_id', $other->id)->count())->toBe(1)
        // The other tenant's genesis entry is its own chain (sequence 1, no previous).
        ->and(AuditLog::withoutGlobalScopes()->where('tenant_id', $other->id)->first()->previous_hash)->toBeNull();
});

it('redacts sensitive attributes', function () {
    $entry = app(Tenancy::class)->runFor($this->tenant, fn () => app(Auditor::class)->log(
        'user.updated',
        newValues: ['name' => 'Ada', 'password' => 'super-secret', 'remember_token' => 'abc'],
    ));

    expect($entry->new_values['name'])->toBe('Ada')
        ->and($entry->new_values['password'])->toBe('[redacted]')
        ->and($entry->new_values['remember_token'])->toBe('[redacted]')
        ->and($entry->hasValidHash())->toBeTrue();
});

<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Spatie\Permission\PermissionRegistrar;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // spatie caches its permission collection. The cache store is NOT reset
        // by RefreshDatabase, so on CI (where the cache is Redis, shared across
        // tests) a later test can read a stale collection that points at rows a
        // previous test's rollback deleted — surfacing as "PermissionDoesNotExist"
        // in the seeder. Forgetting it here gives every test a clean slate.
        // (Locally the cache is the per-test `array` store, so this is a no-op.)
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}

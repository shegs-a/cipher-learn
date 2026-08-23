<?php

declare(strict_types=1);

namespace App\Providers;

use App\Hris\HrisManager;
use Illuminate\Support\ServiceProvider;

class HrisServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The manager itself is a singleton (it is just a resolver plus a small
        // per-tenant cache); the ADAPTERS it hands out are per-tenant, because
        // their credentials come from tenants.settings. See HrisManager.
        $this->app->singleton(HrisManager::class);
    }

    public function boot(): void
    {
        //
    }
}

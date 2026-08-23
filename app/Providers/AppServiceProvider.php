<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Tenancy;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One tenant context per request/worker lifecycle. The BelongsToTenant
        // global scope resolves this same instance.
        $this->app->singleton(Tenancy::class);
    }

    public function boot(): void
    {
        //
    }
}

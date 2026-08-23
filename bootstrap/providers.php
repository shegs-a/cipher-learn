<?php

use App\Providers\AppServiceProvider;
use App\Providers\AuthServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\HrisServiceProvider;

return [
    AppServiceProvider::class,
    AuthServiceProvider::class,
    HrisServiceProvider::class,
    AdminPanelProvider::class,
];

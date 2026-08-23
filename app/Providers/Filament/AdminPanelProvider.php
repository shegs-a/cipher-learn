<?php

namespace App\Providers\Filament;

use App\Http\Middleware\BindCurrentTenant;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\MenuItem;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            // No ->login(): there is exactly one door, the unified /login route
            // (App\Livewire\Auth\Login). Filament redirects guests to the app's
            // named `login` route instead of a per-panel login page.
            ->brandName('CipherLearn')
            // The book-icon + wordmark logo (matches the learner portal). brandName
            // is kept for the browser title / accessibility.
            ->brandLogo(fn () => view('filament.brand'))
            // Match the mark's height so Filament doesn't scale the logo down (which
            // squeezes the gap between the book and the wordmark).
            ->brandLogoHeight('2rem')
            ->colors([
                // Brand primary #4f46e5 (see tailwind.config.js / design tokens).
                'primary' => '#4f46e5',
            ])
            ->font('Inter')
            // In-app notifications (the bell) for panel users — admins/managers see
            // request-approval and other alerts here, backed by the notifications
            // table's `database` channel. Polled so they arrive without a refresh.
            ->databaseNotifications()
            ->databaseNotificationsPolling('60s')
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            // AccountWidget stays (a friendly greeting); the Filament promo widget
            // is dropped now that this is a real operational dashboard. The
            // overview widgets are auto-discovered from app/Filament/Widgets.
            ->widgets([
                Widgets\AccountWidget::class,
            ])
            // Portal switcher: a link from the admin panel to the learner portal,
            // so a multi-role identity (an admin who is also a learner) can move
            // between the portals their roles permit.
            ->userMenuItems([
                MenuItem::make()
                    ->label('Learner portal')
                    ->icon('heroicon-o-academic-cap')
                    ->url(fn (): string => route('portal')),
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                // Establish the tenant from the authenticated admin so every
                // resource query is tenant-scoped by the global scope.
                BindCurrentTenant::class,
            ]);
    }
}
